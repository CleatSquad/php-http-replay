<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A real client that can honor per-request transport options (e.g. a
 * timeout override) that plain PSR-18 sendRequest() has no slot for.
 */
interface OptionsAwareClientInterface extends ClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function sendRequestWithOptions(RequestInterface $request, array $options): ResponseInterface;
}
