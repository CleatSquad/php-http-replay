<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Enum;

enum ExecutionMatchingMode: string
{
    case Sequential = 'sequential';
    case Unordered = 'unordered';
}
