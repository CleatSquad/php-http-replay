<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Naming;

use CleatSquad\HttpReplay\Naming\CallbackCassetteNamingStrategy;
use CleatSquad\HttpReplay\Naming\StaticCassetteNamingStrategy;
use PHPUnit\Framework\TestCase;

final class CassetteNamingStrategyTest extends TestCase
{
    public function testStaticCassetteNamingStrategy(): void
    {
        $strategy = new StaticCassetteNamingStrategy('custom_name');
        $this->assertSame('custom_name', $strategy->name());
    }

    public function testCallbackCassetteNamingStrategy(): void
    {
        $strategy = new CallbackCassetteNamingStrategy(fn () => 'dynamic_name_' . 123);
        $this->assertSame('dynamic_name_123', $strategy->name());
    }
}
