<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Exception;

use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\HttpReplayException;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Model\MatchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CassetteNotFoundException::class)]
#[CoversClass(RequestMismatchException::class)]
final class ExceptionTest extends TestCase
{
    public function testCassetteNotFoundException(): void
    {
        $exception = CassetteNotFoundException::forCassetteName('github-api');

        $this->assertInstanceOf(HttpReplayException::class, $exception);
        $this->assertSame('github-api', $exception->cassetteName());
        $this->assertSame('Cassette "github-api" was not found.', $exception->getMessage());
    }

    public function testRequestMismatchException(): void
    {
        $matchResult = MatchResult::mismatch([
            'method' => 'Expected GET, got POST',
            'uri' => 'Expected /api/users, got /api/posts',
        ]);

        $exception = RequestMismatchException::forMismatch('my-cassette', 2, $matchResult);

        $this->assertInstanceOf(HttpReplayException::class, $exception);
        $this->assertSame('my-cassette', $exception->cassetteName());
        $this->assertSame(2, $exception->index());
        $this->assertSame($matchResult, $exception->matchResult());
        $this->assertStringContainsString('my-cassette', $exception->getMessage());
        $this->assertStringContainsString('index 2', $exception->getMessage());
        $this->assertStringContainsString('method: Expected GET, got POST', $exception->getMessage());
    }
}
