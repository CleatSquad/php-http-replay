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

    /** Multi-line CLI diagnostic string for differences. */
    public function toCliString(bool $colorize = false): string
    {
        if ($this->matched || count($this->differences) === 0) {
            return 'Match successful: no differences detected.';
        }

        $lines = [];
        foreach ($this->differences as $key => $val) {
            if ($colorize) {
                $lines[] = sprintf("  \033[33m%s\033[0m: \033[31m%s\033[0m", $key, $val);
            } else {
                $lines[] = sprintf('  %s: %s', $key, $val);
            }
        }

        return "Request mismatch:\n" . implode("\n", $lines);
    }
}
