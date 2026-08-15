<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Model;

use CleatSquad\HttpReplay\Model\Exchange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Exchange::class)]
final class ExchangeTest extends TestCase
{
    public function testExchangeGetters(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $exchange = new Exchange($request, $response);

        $this->assertSame($request, $exchange->request());
        $this->assertSame($response, $exchange->response());
    }
}
