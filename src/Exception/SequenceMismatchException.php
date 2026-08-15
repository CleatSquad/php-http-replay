<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use CleatSquad\HttpReplay\Model\MatchResult;

final class SequenceMismatchException extends RequestMismatchException
{
    public static function forSequenceMismatch(string $cassetteName, int $index, MatchResult $matchResult): self
    {
        $diffs = [];
        foreach ($matchResult->differences() as $key => $val) {
            $diffs[] = sprintf('%s: %s', $key, $val);
        }

        $formattedDiffs = count($diffs) > 0 ? implode('; ', $diffs) : 'No specific differences detailed';

        $message = sprintf(
            'Sequence mismatch in cassette "%s" at index %d. Differences: [%s]',
            $cassetteName,
            $index,
            $formattedDiffs
        );

        return new self($message, $cassetteName, $index, $matchResult);
    }
}
