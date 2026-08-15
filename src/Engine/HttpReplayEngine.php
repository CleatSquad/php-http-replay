<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Engine;

use CleatSquad\HttpReplay\Contract\CassetteNamingStrategyInterface;
use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\ExchangeSelectorInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Exception\SequenceExhaustedException;
use CleatSquad\HttpReplay\Exception\SequenceMismatchException;
use CleatSquad\HttpReplay\Exception\UnorderedMismatchException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\MatchResult;
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
    ) {
        if ($this->realClient === null && ($this->mode === ExecutionMode::Record || $this->mode === ExecutionMode::Passthrough)) {
            throw new \LogicException(sprintf('Real HTTP client (PSR-18 ClientInterface) is required for %s mode.', $this->mode->value));
        }

        $this->selector = $selector ?? match ($this->matchingMode) {
            ExecutionMatchingMode::Sequential => new SequentialExchangeSelector(),
            ExecutionMatchingMode::Unordered => new UnorderedExchangeSelector(),
        };
    }

    public function stats(): ReplayStats
    {
        $name = $this->resolveCassetteName();
        $cassette = $this->cassetteStore->load($name);
        $totalExchanges = $cassette !== null ? count($cassette->exchanges()) : 0;
        $replayedCount = count($this->consumedIndices);

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

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return match ($this->mode) {
            ExecutionMode::Passthrough => $this->handlePassthrough($request),
            ExecutionMode::Replay => $this->handleReplay($request),
            ExecutionMode::Record => $this->handleRecord($request),
            ExecutionMode::RecordOnce => $this->handleRecordOnce($request),
        };
    }

    private function resolveCassetteName(): string
    {
        if (is_string($this->cassetteName)) {
            return $this->cassetteName;
        }

        return $this->cassetteName->name();
    }

    private function handlePassthrough(RequestInterface $request): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Passthrough mode.');
        }

        return $this->realClient->sendRequest($request);
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

    private function handleRecord(RequestInterface $request): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Record mode.');
        }

        $name = $this->resolveCassetteName();

        $response = $this->realClient->sendRequest($request);

        $sanitizedRequest = $this->sanitizer->sanitizeRequest($request);
        $sanitizedResponse = $this->sanitizer->sanitizeResponse($response);

        $existingCassette = $this->cassetteStore->load($name);
        $existingExchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];
        $version = \CleatSquad\HttpReplay\Storage\JsonCassetteStore::CURRENT_SCHEMA_VERSION;
        $metadata = $existingCassette !== null ? $existingCassette->metadata() : [];

        $newExchange = new Exchange($sanitizedRequest, $sanitizedResponse);
        $updatedExchanges = [...$existingExchanges, $newExchange];

        $newCassette = new Cassette($version, $updatedExchanges, $metadata);

        $this->cassetteStore->save($name, $newCassette);
        $this->recordedIndices[count($existingExchanges)] = true;
        $this->recordedCount++;

        return $response;
    }

    private function handleRecordOnce(RequestInterface $request): ResponseInterface
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

        return $this->handleRecord($request);
    }
}
