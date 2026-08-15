<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use CleatSquad\HttpReplay\Model\MatchResult;

final class UnorderedMismatchException extends RequestMismatchException
{
    /**
     * @param array<string, mixed> $context Additional diagnostic metadata (e.g. inspectedCount, consumedCount)
     */
    private function __construct(
        string $message,
        string $cassetteName,
        int $bestCandidateIndex,
        MatchResult $bestMatchResult,
        private readonly int $inspectedCount,
        private readonly int $consumedCount,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $cassetteName, $bestCandidateIndex, $bestMatchResult);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function forUnorderedMismatch(
        string $cassetteName,
        int $bestCandidateIndex,
        MatchResult $bestMatchResult,
        int $inspectedCount,
        int $consumedCount,
        array $context = [],
    ): self {
        $diffs = [];
        foreach ($bestMatchResult->differences() as $key => $val) {
            $diffs[] = sprintf('%s: %s', $key, $val);
        }

        $formattedDiffs = count($diffs) > 0 ? implode('; ', $diffs) : 'No specific differences detailed';

        $message = sprintf(
            'Unordered request mismatch in cassette "%s" (inspected: %d, consumed: %d, best candidate index: %d). Differences: [%s]',
            $cassetteName,
            $inspectedCount,
            $consumedCount,
            $bestCandidateIndex,
            $formattedDiffs
        );

        return new self(
            $message,
            $cassetteName,
            $bestCandidateIndex,
            $bestMatchResult,
            $inspectedCount,
            $consumedCount,
            $context
        );
    }

    public function inspectedCount(): int
    {
        return $this->inspectedCount;
    }

    public function consumedCount(): int
    {
        return $this->consumedCount;
    }

    public function bestCandidateIndex(): int
    {
        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function toCliString(bool $colorize = false): string
    {
        $header = sprintf(
            'Unordered request mismatch in cassette "%s" (inspected %d, consumed %d, best candidate at index %d):',
            $this->cassetteName(),
            $this->inspectedCount,
            $this->consumedCount,
            $this->index()
        );
        if ($colorize) {
            $header = sprintf("\033[1;31m%s\033[0m", $header);
        }

        return $header . "\n" . $this->matchResult()->toCliString($colorize);
    }
}
