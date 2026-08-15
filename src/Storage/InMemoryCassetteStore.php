<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Storage;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Model\Cassette;

final class InMemoryCassetteStore implements CassetteStoreInterface
{
    /**
     * @var array<string, Cassette>
     */
    private array $cassettes = [];

    /**
     * @param array<string, Cassette> $initialCassettes
     */
    public function __construct(array $initialCassettes = [])
    {
        foreach ($initialCassettes as $name => $cassette) {
            $this->save($name, $cassette);
        }
    }

    public function exists(string $name): bool
    {
        return isset($this->cassettes[$name]);
    }

    public function load(string $name): ?Cassette
    {
        return $this->cassettes[$name] ?? null;
    }

    public function save(string $name, Cassette $cassette): void
    {
        $this->cassettes[$name] = $cassette;
    }
}
