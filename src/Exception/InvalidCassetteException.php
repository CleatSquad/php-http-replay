<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Exception;

use RuntimeException;

final class InvalidCassetteException extends RuntimeException implements HttpReplayException
{
    public static function malformed(string $cassettePath, string $reason): self
    {
        return new self(sprintf('Malformed cassette at "%s": %s', $cassettePath, $reason));
    }

    public static function unsupportedVersion(string $cassettePath, int $version, int $supportedVersion = 1): self
    {
        return new self(sprintf(
            'Unsupported cassette schema version %d at "%s". Only version %d is supported.',
            $version,
            $cassettePath,
            $supportedVersion
        ));
    }
}
