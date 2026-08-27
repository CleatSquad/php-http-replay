<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Engine;

use CleatSquad\HttpReplay\Contract\CassetteNamingStrategyInterface;
use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\ExchangeSelectorInterface;
use CleatSquad\HttpReplay\Contract\OptionsAwareClientInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\SequenceExhaustedException;
use CleatSquad\HttpReplay\Exception\SequenceMismatchException;
use CleatSquad\HttpReplay\Exception\UnorderedMismatchException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\ReplayStats;
use CleatSquad\HttpReplay\Selector\SequentialExchangeSelector;
use CleatSquad\HttpReplay\Selector\UnorderedExchangeSelector;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpReplayEngine implements ClientInterface
{
    private int $recordedCount = 0;
    /** @var array<int, bool> Cassette indices replayed during this session */
    private array $consumedIndices = [];
    /** @var array<int, bool> Cassette indices written during this session */
    private array $recordedIndices = [];
    private readonly ExchangeSelectorInterface $selector;

    public function __construct(
        private readonly ExecutionMode $mode,
        private readonly CassetteStoreInterface $cassetteStore,
        private readonly string|CassetteNamingStrategyInterface $cassetteName,
        private readonly RequestMatcherInterface $requestMatcher,
        private readonly SanitizerInterface $sanitizer,
        private readonly ?ClientInterface $realClient = null,
        private readonly ExecutionMatchingMode $matchingMode = ExecutionMatchingMode::Sequential,
        ?ExchangeSelectorInterface $selector = null,
        private readonly ?int $exchangeTtl = null,
    ) {
        if ($this->realClient === null && ($this->mode === ExecutionMode::Record || $this->mode === ExecutionMode::Passthrough)) {
            throw new \LogicException(sprintf('Real HTTP client (PSR-18 ClientInterface) is required for %s mode.', $this->mode->value));
        }

        $this->selector = $selector ?? match ($this->matchingMode) {
            ExecutionMatchingMode::Sequential => new SequentialExchangeSelector($this->exchangeTtl),
            ExecutionMatchingMode::Unordered => new UnorderedExchangeSelector($this->exchangeTtl),
        };
    }

    public function stats(): ReplayStats
    {
        $name = $this->resolveCassetteName();
        $cassette = $this->cassetteStore->load($name);
        $totalExchanges = $cassette !== null ? count($cassette->exchanges()) : 0;
        $replayedCount = count($this->consumedIndices);

        // An exchange written during this session was never stale to begin with, so
        // only exchanges the cassette already held and that went unused are reported.
        $unusedIndices = [];
        for ($i = 0; $i < $totalExchanges; $i++) {
            if (!isset($this->consumedIndices[$i]) && !isset($this->recordedIndices[$i])) {
                $unusedIndices[] = $i;
            }
        }

        return new ReplayStats(
            cassetteName: $name,
            totalExchanges: $totalExchanges,
            replayedCount: $replayedCount,
            recordedCount: $this->recordedCount,
            unusedIndices: $unusedIndices,
            matchingMode: $this->matchingMode,
        );
    }

    /**
     * @param array<string, mixed> $options Forwarded to the real client only
     *   when it implements OptionsAwareClientInterface — a plain PSR-18
     *   client has no slot for these and is called as before.
     */
    public function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        return match ($this->mode) {
            ExecutionMode::Passthrough => $this->handlePassthrough($request, $options),
            ExecutionMode::Replay => $this->handleReplay($request),
            ExecutionMode::Record => $this->handleRecord($request, $options),
            ExecutionMode::RecordOnce => $this->handleRecordOnce($request, $options),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sendToRealClient(ClientInterface $client, RequestInterface $request, array $options): ResponseInterface
    {
        if ($options !== [] && $client instanceof OptionsAwareClientInterface) {
            return $client->sendRequestWithOptions($request, $options);
        }

        return $client->sendRequest($request);
    }

    private function resolveCassetteName(): string
    {
        if (is_string($this->cassetteName)) {
            return $this->cassetteName;
        }

        return $this->cassetteName->name();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handlePassthrough(RequestInterface $request, array $options = []): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Passthrough mode.');
        }

        return $this->sendToRealClient($this->realClient, $request, $options);
    }

    private function handleReplay(RequestInterface $request): ResponseInterface
    {
        $name = $this->resolveCassetteName();
        $cassette = $this->cassetteStore->load($name);
        if ($cassette === null) {
            throw CassetteNotFoundException::forCassetteName($name);
        }

        $exchanges = $cassette->exchanges();
        $result = $this->selector->select($request, $exchanges, $this->consumedIndices, $this->requestMatcher);

        if ($result->isMatched() && $result->exchange !== null) {
            $this->consumedIndices[$result->index] = true;

            return $result->exchange->response();
        }

        if ($result->isExhausted) {
            throw SequenceExhaustedException::forCassette($name, $result->index, count($exchanges));
        }

        if ($this->matchingMode === ExecutionMatchingMode::Sequential) {
            throw SequenceMismatchException::forSequenceMismatch($name, $result->index, $result->matchResult);
        }

        throw UnorderedMismatchException::forUnorderedMismatch(
            cassetteName: $name,
            bestCandidateIndex: $result->index,
            bestMatchResult: $result->matchResult,
            inspectedCount: $result->inspectedCount,
            consumedCount: count($this->consumedIndices),
            context: [
                'matchingMode' => $this->matchingMode->value,
                'totalExchanges' => count($exchanges),
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleRecord(RequestInterface $request, array $options = []): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Record mode.');
        }

        $name = $this->resolveCassetteName();

        $response = $this->sendToRealClient($this->realClient, $request, $options);

        $sanitizedRequest = $this->sanitizer->sanitizeRequest($request);
        $sanitizedResponse = $this->sanitizer->sanitizeResponse($response);

        $existingCassette = $this->cassetteStore->load($name);
        $existingExchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];
        $version = $existingCassette !== null ? $existingCassette->version() : 1;
        $metadata = $existingCassette !== null ? $existingCassette->metadata() : [];

        $newExchange = new Exchange($sanitizedRequest, $sanitizedResponse, time());
        $updatedExchanges = [...$existingExchanges, $newExchange];

        $newCassette = new Cassette($version, $updatedExchanges, $metadata);

        $this->cassetteStore->save($name, $newCassette);
        $this->recordedIndices[count($existingExchanges)] = true;
        $this->recordedCount++;

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleRecordOnce(RequestInterface $request, array $options = []): ResponseInterface
    {
        $name = $this->resolveCassetteName();
        $existingCassette = $this->cassetteStore->load($name);
        $exchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];

        if (count($exchanges) > 0) {
            $result = $this->selector->select($request, $exchanges, $this->consumedIndices, $this->requestMatcher);
            if ($result->isMatched() && $result->exchange !== null) {
                $this->consumedIndices[$result->index] = true;

                return $result->exchange->response();
            }
        }

        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for RecordOnce mode when recording a missing exchange.');
        }

        // No index is consumed here: the recorded exchange is appended at the end of
        // the cassette and stays replayable for an identical request issued later by
        // the same engine instance.
        return $this->handleRecord($request, $options);
    }
}
