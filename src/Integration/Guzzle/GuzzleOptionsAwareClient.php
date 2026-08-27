<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Integration\Guzzle;

use CleatSquad\HttpReplay\Contract\OptionsAwareClientInterface;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps a Guzzle client so a real HTTP call made through the replay engine
 * (Record/RecordOnce/Passthrough) still honors per-request Guzzle options
 * such as `timeout` — plain PSR-18 sendRequest() has no slot for these.
 */
final readonly class GuzzleOptionsAwareClient implements OptionsAwareClientInterface
{
    public function __construct(private GuzzleClientInterface $client)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->send($request);
    }

    public function sendRequestWithOptions(RequestInterface $request, array $options): ResponseInterface
    {
        return $this->client->send($request, $options);
    }
}
