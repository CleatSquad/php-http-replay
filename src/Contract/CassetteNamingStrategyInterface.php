<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

interface CassetteNamingStrategyInterface
{
    public function name(): string;
}
