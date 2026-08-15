<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;

final readonly class ReplayStats
{
    /**
     * @param string $cassetteName Name of the cassette inspected
     * @param int $totalExchanges Total number of exchanges currently stored in the cassette
     * @param int $replayedCount Number of exchanges replayed during the session
     * @param int $recordedCount Number of exchanges newly recorded during the session
     * @param list<int> $unusedIndices List of 0-based exchange indices in the cassette that were never replayed
     * @param ExecutionMatchingMode $matchingMode Matching mode the engine ran with
     */
    public function __construct(
        public string $cassetteName,
        public int $totalExchanges,
        public int $replayedCount,
        public int $recordedCount,
        public array $unusedIndices,
        public ExecutionMatchingMode $matchingMode = ExecutionMatchingMode::Sequential,
    ) {
    }

    public function isFullyConsumed(): bool
    {
        return count($this->unusedIndices) === 0 && $this->totalExchanges > 0;
    }

    public function hasUnusedExchanges(): bool
    {
        return count($this->unusedIndices) > 0;
    }
}
