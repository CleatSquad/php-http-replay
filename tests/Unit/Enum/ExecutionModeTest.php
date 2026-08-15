<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Enum;

use CleatSquad\HttpReplay\Enum\ExecutionMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecutionMode::class)]
final class ExecutionModeTest extends TestCase
{
    public function testExecutionModeValues(): void
    {
        $this->assertSame('replay', ExecutionMode::Replay->value);
        $this->assertSame('record', ExecutionMode::Record->value);
        $this->assertSame('passthrough', ExecutionMode::Passthrough->value);
    }
}
