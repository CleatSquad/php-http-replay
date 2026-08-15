<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use CleatSquad\HttpReplay\Model\MatchResult;
use RuntimeException;

final class RequestMismatchException extends RuntimeException implements HttpReplayException
{
    private function __construct(
        string $message,
        private readonly string $cassetteName,
        private readonly int $index,
        private readonly MatchResult $matchResult,
    ) {
        parent::__construct($message);
    }

    public static function forMismatch(string $cassetteName, int $index, MatchResult $matchResult): self
    {
        $diffs = [];
        foreach ($matchResult->differences() as $key => $val) {
            $diffs[] = sprintf('%s: %s', $key, $val);
        }

        $formattedDiffs = count($diffs) > 0 ? implode('; ', $diffs) : 'No specific differences detailed';

        $message = sprintf(
            'Request mismatch in cassette "%s" at index %d. Differences: [%s]',
            $cassetteName,
            $index,
            $formattedDiffs
        );

        return new self($message, $cassetteName, $index, $matchResult);
    }

    public function cassetteName(): string
    {
        return $this->cassetteName;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function matchResult(): MatchResult
    {
        return $this->matchResult;
    }
}
