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
            // Guzzle's handler signature types $options loosely as array<mixed>, but every
            // request-options key it ever sends (timeout, headers, auth, ...) is a string.
            /** @var array<string, mixed> $options */
            return Create::promiseFor($this->engine->sendRequest($request, self::timeoutOptionsOnly($options)));
        } catch (\Throwable $e) {
            return Create::rejectionFor($e);
        }
    }

    /**
     * Only `timeout`/`connect_timeout` are forwarded to the real client on a
     * live call — anything else (e.g. `stream`, which switches the response
     * body to an unbuffered live handle) changes transport behavior this
     * engine was never built to handle and must stay inert, as it always was
     * before this per-request passthrough existed.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function timeoutOptionsOnly(array $options): array
    {
        return array_intersect_key($options, ['timeout' => true, 'connect_timeout' => true]);
    }
}
