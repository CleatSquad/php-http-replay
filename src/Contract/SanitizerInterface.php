<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface SanitizerInterface
{
    public function sanitizeRequest(RequestInterface $request): RequestInterface;

    public function sanitizeResponse(ResponseInterface $response): ResponseInterface;
}
