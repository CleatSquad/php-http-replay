<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Integration\Guzzle;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GuzzleReplayHandler
{

    public function __construct(
        private readonly HttpReplayEngine $engine,
    ) {
    }

    /**
     * Guzzle Handler signature: `__invoke(RequestInterface $request, array $options): PromiseInterface`
     *
     * @param  array<mixed> $options
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        try {
            return Create::promiseFor($this->engine->sendRequest($request));
        } catch (\Throwable $e) {
            return Create::rejectionFor($e);
        }
    }
}
