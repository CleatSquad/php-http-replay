<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Exception;

use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Exception\SequenceExhaustedException;
use CleatSquad\HttpReplay\Exception\SequenceMismatchException;
use CleatSquad\HttpReplay\Model\MatchResult;
use PHPUnit\Framework\TestCase;

final class SequenceExceptionTest extends TestCase
{
    public function testSequenceExhaustedException(): void
    {
        $e = SequenceExhaustedException::forCassette('my_cassette', 3, 3);

        $this->assertInstanceOf(RequestMismatchException::class, $e);
        $this->assertSame('my_cassette', $e->cassetteName());
        $this->assertSame(3, $e->requestedIndex());
        $this->assertSame(3, $e->totalExchanges());
        $this->assertStringContainsString('Cassette sequence exhausted for "my_cassette"', $e->getMessage());
    }

    public function testSequenceMismatchException(): void
    {
        $result = MatchResult::mismatch(['header.x-id' => 'Header mismatch']);
        $e = SequenceMismatchException::forSequenceMismatch('my_cassette', 1, $result);

        $this->assertInstanceOf(RequestMismatchException::class, $e);
        $this->assertSame('my_cassette', $e->cassetteName());
        $this->assertSame(1, $e->index());
        $this->assertSame($result, $e->matchResult());
        $this->assertStringContainsString('Sequence mismatch in cassette "my_cassette"', $e->getMessage());
    }
}
