<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

final readonly class ExchangeSelectionResult
{
    /**
     * @param int $index Index of matched exchange, or best candidate index on mismatch
     * @param Exchange|null $exchange Matched exchange object, or null if mismatch
     * @param MatchResult $matchResult Detailed match result
     * @param int $inspectedCount Total unconsumed candidates inspected
     * @param bool $isExhausted True if sequence or unconsumed candidates are exhausted
     */
    private function __construct(
        public int $index,
        public ?Exchange $exchange,
        public MatchResult $matchResult,
        public int $inspectedCount,
        public bool $isExhausted = false,
    ) {
    }

    public static function match(int $index, Exchange $exchange, MatchResult $matchResult, int $inspectedCount = 1): self
    {
        return new self(
            index: $index,
            exchange: $exchange,
            matchResult: $matchResult,
            inspectedCount: $inspectedCount,
            isExhausted: false
        );
    }

    public static function mismatch(int $bestIndex, MatchResult $bestMatchResult, int $inspectedCount): self
    {
        return new self(
            index: $bestIndex,
            exchange: null,
            matchResult: $bestMatchResult,
            inspectedCount: $inspectedCount,
            isExhausted: false
        );
    }

    public static function exhausted(int $index, MatchResult $matchResult): self
    {
        return new self(
            index: $index,
            exchange: null,
            matchResult: $matchResult,
            inspectedCount: 0,
            isExhausted: true
        );
    }

    public function isMatched(): bool
    {
        return $this->exchange !== null && $this->matchResult->matched();
    }
}
