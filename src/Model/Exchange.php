<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class Exchange
{
    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response,
        private ?int $recordedAt = null,
    ) {
    }

    public function request(): RequestInterface
    {
        return $this->request;
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function recordedAt(): ?int
    {
        return $this->recordedAt;
    }

    public function isExpired(int $ttlSeconds, ?int $now = null): bool
    {
        if ($this->recordedAt === null || $ttlSeconds <= 0) {
            return false;
        }

        return (($now ?? time()) - $this->recordedAt) > $ttlSeconds;
    }
}
