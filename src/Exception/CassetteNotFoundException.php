<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use RuntimeException;

final class CassetteNotFoundException extends RuntimeException implements HttpReplayException
{
    private function __construct(
        string $message,
        private readonly string $cassetteName,
    ) {
        parent::__construct($message);
    }

    public static function forCassetteName(string $name): self
    {
        return new self(
            sprintf('Cassette "%s" was not found.', $name),
            $name
        );
    }

    public function cassetteName(): string
    {
        return $this->cassetteName;
    }
}
