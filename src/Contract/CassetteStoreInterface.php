<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

use CleatSquad\HttpReplay\Model\Cassette;

interface CassetteStoreInterface
{
    public function load(string $name): ?Cassette;

    public function save(string $name, Cassette $cassette): void;
}
