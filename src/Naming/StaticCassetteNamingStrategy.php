<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Naming;

use CleatSquad\HttpReplay\Contract\CassetteNamingStrategyInterface;

final readonly class StaticCassetteNamingStrategy implements CassetteNamingStrategyInterface
{
    public function __construct(
        private string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }
}
