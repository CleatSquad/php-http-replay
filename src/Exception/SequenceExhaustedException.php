<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use CleatSquad\HttpReplay\Model\MatchResult;

final class SequenceExhaustedException extends RequestMismatchException
{
    private function __construct(
        string $message,
        string $cassetteName,
        int $requestedIndex,
        private readonly int $totalExchanges,
    ) {
        $differences = ['index' => sprintf('Cassette sequence exhausted: index %d requested, total exchanges: %d', $requestedIndex, $totalExchanges)];
        $matchResult = MatchResult::mismatch($differences);
        parent::__construct($message, $cassetteName, $requestedIndex, $matchResult);
    }

    public static function forCassette(string $cassetteName, int $requestedIndex, int $totalExchanges): self
    {
        $message = sprintf(
            'Cassette sequence exhausted for "%s": requested index %d, total recorded exchanges %d.',
            $cassetteName,
            $requestedIndex,
            $totalExchanges
        );

        return new self($message, $cassetteName, $requestedIndex, $totalExchanges);
    }

    public function requestedIndex(): int
    {
        return $this->index();
    }

    public function totalExchanges(): int
    {
        return $this->totalExchanges;
    }
}
