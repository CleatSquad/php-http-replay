<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Engine;

use CleatSquad\HttpReplay\Contract\CassetteNamingStrategyInterface;
use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Exception\SequenceExhaustedException;
use CleatSquad\HttpReplay\Exception\SequenceMismatchException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\ReplayStats;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpReplayEngine implements ClientInterface
{
    private int $replayIndex = 0;
    private int $recordedCount = 0;
    /** @var array<int, bool> Cassette indices replayed during this session */
    private array $consumedIndices = [];
    /** @var array<int, bool> Cassette indices written during this session */
    private array $recordedIndices = [];

    public function __construct(
        private readonly ExecutionMode $mode,
        private readonly CassetteStoreInterface $cassetteStore,
        private readonly string|CassetteNamingStrategyInterface $cassetteName,
        private readonly RequestMatcherInterface $requestMatcher,
        private readonly SanitizerInterface $sanitizer,
        private readonly ?ClientInterface $realClient = null,
    ) {
        if ($this->realClient === null && ($this->mode === ExecutionMode::Record || $this->mode === ExecutionMode::Passthrough)) {
            throw new \LogicException(sprintf('Real HTTP client (PSR-18 ClientInterface) is required for %s mode.', $this->mode->value));
        }
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
        if (!array_key_exists($this->replayIndex, $exchanges)) {
            throw SequenceExhaustedException::forCassette($name, $this->replayIndex, count($exchanges));
        }

        $recordedExchange = $exchanges[$this->replayIndex];
        $matchResult = $this->requestMatcher->match($request, $recordedExchange);

        if (!$matchResult->matched()) {
            throw SequenceMismatchException::forSequenceMismatch($name, $this->replayIndex, $matchResult);
        }

        $this->consumedIndices[$this->replayIndex] = true;
        $this->replayIndex++;

        return $recordedExchange->response();
    }

    private function handleRecord(RequestInterface $request): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Record mode.');
        }

        $name = $this->resolveCassetteName();

        // 1. Send ORIGINAL unsanitized request via real client
        $response = $this->realClient->sendRequest($request);

        // 2. Create sanitized copies for persistence ONLY
        $sanitizedRequest = $this->sanitizer->sanitizeRequest($request);
        $sanitizedResponse = $this->sanitizer->sanitizeResponse($response);

        // 3. Load existing cassette or start fresh
        $existingCassette = $this->cassetteStore->load($name);
        $existingExchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];
        $version = $existingCassette !== null ? $existingCassette->version() : 1;
        $metadata = $existingCassette !== null ? $existingCassette->metadata() : [];

        // 4. Append new sanitized exchange
        $newExchange = new Exchange($sanitizedRequest, $sanitizedResponse);
        $updatedExchanges = [...$existingExchanges, $newExchange];

        $newCassette = new Cassette($version, $updatedExchanges, $metadata);

        // 5. Save cassette atomically
        $this->cassetteStore->save($name, $newCassette);
        $this->recordedCount++;

        // 6. Return ORIGINAL unsanitized response
        return $response;
    }

    private function handleRecordOnce(RequestInterface $request): ResponseInterface
    {
        $name = $this->resolveCassetteName();
        $existingCassette = $this->cassetteStore->load($name);
        $exchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];

        if (array_key_exists($this->replayIndex, $exchanges)) {
            $recordedExchange = $exchanges[$this->replayIndex];
            $matchResult = $this->requestMatcher->match($request, $recordedExchange);
            if ($matchResult->matched()) {
                $this->consumedIndices[$this->replayIndex] = true;
                $this->replayIndex++;

                return $recordedExchange->response();
            }
        }

        // On miss: record new exchange if realClient is present
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for RecordOnce mode when recording a missing exchange.');
        }

        // The replay cursor is intentionally left untouched: a recorded exchange is
        // appended at the end of the cassette and stays replayable for an identical
        // request issued later by the same engine instance.
        return $this->handleRecord($request);
    }
}
