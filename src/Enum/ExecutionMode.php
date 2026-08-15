<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Enum;

enum ExecutionMode: string
{
    case Replay = 'replay';
    case Record = 'record';
    case Passthrough = 'passthrough';
    case RecordOnce = 'record_once';
}
