<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Engine;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpReplayEngine implements ClientInterface
{
    private int $replayIndex = 0;

    public function __construct(
        private readonly ExecutionMode $mode,
        private readonly CassetteStoreInterface $cassetteStore,
        private readonly string $cassetteName,
        private readonly RequestMatcherInterface $requestMatcher,
        private readonly SanitizerInterface $sanitizer,
        private readonly ?ClientInterface $realClient = null,
    ) {
        if ($this->realClient === null && ($this->mode === ExecutionMode::Record || $this->mode === ExecutionMode::Passthrough)) {
            throw new \LogicException(sprintf('Real HTTP client (PSR-18 ClientInterface) is required for %s mode.', $this->mode->value));
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return match ($this->mode) {
            ExecutionMode::Passthrough => $this->handlePassthrough($request),
            ExecutionMode::Replay => $this->handleReplay($request),
            ExecutionMode::Record => $this->handleRecord($request),
        };
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
        $cassette = $this->cassetteStore->load($this->cassetteName);
        if ($cassette === null) {
            throw CassetteNotFoundException::forCassetteName($this->cassetteName);
        }

        $exchanges = $cassette->exchanges();
        if (!array_key_exists($this->replayIndex, $exchanges)) {
            $differences = ['index' => sprintf('Cassette index %d out of bounds (total exchanges: %d)', $this->replayIndex, count($exchanges))];
            throw RequestMismatchException::forMismatch($this->cassetteName, $this->replayIndex, \CleatSquad\HttpReplay\Model\MatchResult::mismatch($differences));
        }

        $recordedExchange = $exchanges[$this->replayIndex];
        $matchResult = $this->requestMatcher->match($request, $recordedExchange);

        if (!$matchResult->matched()) {
            throw RequestMismatchException::forMismatch($this->cassetteName, $this->replayIndex, $matchResult);
        }

        $this->replayIndex++;

        return $recordedExchange->response();
    }

    private function handleRecord(RequestInterface $request): ResponseInterface
    {
        if ($this->realClient === null) {
            throw new \LogicException('Real HTTP client (PSR-18 ClientInterface) is required for Record mode.');
        }

        // 1. Send ORIGINAL unsanitized request via real client
        $response = $this->realClient->sendRequest($request);

        // 2. Create sanitized copies for persistence ONLY
        $sanitizedRequest = $this->sanitizer->sanitizeRequest($request);
        $sanitizedResponse = $this->sanitizer->sanitizeResponse($response);

        // 3. Load existing cassette or start fresh
        $existingCassette = $this->cassetteStore->load($this->cassetteName);
        $existingExchanges = $existingCassette !== null ? $existingCassette->exchanges() : [];
        $version = $existingCassette !== null ? $existingCassette->version() : 1;
        $metadata = $existingCassette !== null ? $existingCassette->metadata() : [];

        // 4. Append new sanitized exchange
        $newExchange = new Exchange($sanitizedRequest, $sanitizedResponse);
        $updatedExchanges = [...$existingExchanges, $newExchange];

        $newCassette = new Cassette($version, $updatedExchanges, $metadata);

        // 5. Save cassette atomically
        $this->cassetteStore->save($this->cassetteName, $newCassette);

        // 6. Return ORIGINAL unsanitized response
        return $response;
    }
}
