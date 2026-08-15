<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Model;

use CleatSquad\HttpReplay\Model\MatchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchResult::class)]
final class MatchResultTest extends TestCase
{
    public function testSuccessResult(): void
    {
        $result = MatchResult::success();
        $this->assertTrue($result->matched());
        $this->assertSame([], $result->differences());
    }

    public function testMismatchResult(): void
    {
        $differences = ['header:authorization' => 'Missing expected header'];
        $result = MatchResult::mismatch($differences);
        $this->assertFalse($result->matched());
        $this->assertSame($differences, $result->differences());
    }
}
