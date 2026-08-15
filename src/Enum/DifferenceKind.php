<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Enum;

/**
 * The part of a request two matchers disagreed on.
 * A mismatch inside a structured body is Body plus a path, not its own case.
 */
enum DifferenceKind: string
{
    case Method = 'method';
    case Uri = 'uri';
    case Header = 'header';
    case Body = 'body';
}
