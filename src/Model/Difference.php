<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

use CleatSquad\HttpReplay\Enum\DifferenceKind;

/**
 * One reason a request did not match a recorded one, kept structured so callers
 * can render, group or filter it instead of parsing a sentence.
 */
final readonly class Difference
{
    /**
     * @param string|null $path Where inside the kind the values differ: a header
     *                          name, a body path such as "messages.0.content",
     *                          or null when the kind has no inner location.
     */
    private function __construct(
        public DifferenceKind $kind,
        public ?string $path,
        public string $expected,
        public string $actual,
    ) {
    }

    public static function method(string $expected, string $actual): self
    {
        return new self(DifferenceKind::Method, null, $expected, $actual);
    }

    public static function uri(string $expected, string $actual): self
    {
        return new self(DifferenceKind::Uri, null, $expected, $actual);
    }

    public static function header(string $name, string $expected, string $actual): self
    {
        return new self(DifferenceKind::Header, $name, $expected, $actual);
    }

    /** A whole-body mismatch, when no finer location is available. */
    public static function body(string $expected, string $actual): self
    {
        return new self(DifferenceKind::Body, null, $expected, $actual);
    }

    /** A mismatch located inside a structured body, e.g. "messages.0.content". */
    public static function bodyAt(string $path, string $expected, string $actual): self
    {
        return new self(DifferenceKind::Body, $path, $expected, $actual);
    }

    /** Single-line rendering, e.g. `Body mismatch at messages.0.content`. */
    public function describe(): string
    {
        $label = ucfirst($this->kind->value) . ' mismatch';

        return $this->path === null ? $label : $label . ' at ' . $this->path;
    }
}
