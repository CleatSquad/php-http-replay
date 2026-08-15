<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

final readonly class MatchResult
{
    /**
     * @param array<string, string> $differences
     */
    private function __construct(
        private bool $matched,
        private array $differences = [],
    ) {
    }

    public static function success(): self
    {
        return new self(true, []);
    }

    /**
     * @param array<string, string> $differences
     */
    public static function mismatch(array $differences): self
    {
        return new self(false, $differences);
    }

    public function matched(): bool
    {
        return $this->matched;
    }

    /**
     * @return array<string, string>
     */
    public function differences(): array
    {
        return $this->differences;
    }
}
