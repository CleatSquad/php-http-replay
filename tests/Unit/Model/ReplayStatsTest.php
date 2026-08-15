<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Model;

use CleatSquad\HttpReplay\Model\ReplayStats;
use PHPUnit\Framework\TestCase;

final class ReplayStatsTest extends TestCase
{
    public function testReplayStatsConsumptionHelpers(): void
    {
        $statsFullyConsumed = new ReplayStats(
            cassetteName: 'test_cassette',
            totalExchanges: 3,
            replayedCount: 3,
            recordedCount: 0,
            unusedIndices: []
        );

        $this->assertTrue($statsFullyConsumed->isFullyConsumed());
        $this->assertFalse($statsFullyConsumed->hasUnusedExchanges());

        $statsPartial = new ReplayStats(
            cassetteName: 'test_cassette',
            totalExchanges: 3,
            replayedCount: 2,
            recordedCount: 0,
            unusedIndices: [2]
        );

        $this->assertFalse($statsPartial->isFullyConsumed());
        $this->assertTrue($statsPartial->hasUnusedExchanges());
    }
}
