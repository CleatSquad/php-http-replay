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
}
