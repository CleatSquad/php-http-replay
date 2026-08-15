<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Model;

use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Cassette::class)]
final class CassetteTest extends TestCase
{
    public function testEmptyCassetteDefaults(): void
    {
        $cassette = new Cassette();

        $this->assertSame(1, $cassette->version());
        $this->assertSame([], $cassette->exchanges());
        $this->assertSame([], $cassette->metadata());
        $this->assertSame(0, $cassette->count());
        $this->assertTrue($cassette->isEmpty());
    }

    public function testCassetteWithExchangesAndMetadata(): void
    {
        $ex1 = new Exchange(
            $this->createMock(RequestInterface::class),
            $this->createMock(ResponseInterface::class)
        );
        $ex2 = new Exchange(
            $this->createMock(RequestInterface::class),
            $this->createMock(ResponseInterface::class)
        );

        $metadata = ['recorded_at' => '2026-08-15T12:00:00Z'];
        $cassette = new Cassette(2, [$ex1, $ex2], $metadata);

        $this->assertSame(2, $cassette->version());
        $this->assertSame(2, $cassette->count());
        $this->assertFalse($cassette->isEmpty());
        $this->assertSame([$ex1, $ex2], $cassette->exchanges());
        $this->assertSame($metadata, $cassette->metadata());
        $this->assertSame($ex1, $cassette->get(0));
        $this->assertSame($ex2, $cassette->get(1));
    }

    public function testGetOutOfBoundsThrowsException(): void
    {
        $cassette = new Cassette();

        $this->expectException(OutOfBoundsException::class);
        $cassette->get(0);
    }

    public function testInvalidExchangeTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cassette(1, ['invalid']);
    }
}
