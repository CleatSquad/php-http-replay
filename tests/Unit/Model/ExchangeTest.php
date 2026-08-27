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
        $this->assertNull($exchange->recordedAt());
        $this->assertFalse($exchange->isExpired(3600));
    }

    public function testExchangeWithRecordedAt(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $now = 1700000000;

        $exchange = new Exchange($request, $response, $now);

        $this->assertSame($now, $exchange->recordedAt());
        $this->assertFalse($exchange->isExpired(3600, now: $now + 1800));
        $this->assertTrue($exchange->isExpired(3600, now: $now + 3601));
        $this->assertFalse($exchange->isExpired(0, now: $now + 3601), 'TTL <= 0 means no expiration');
    }
}
