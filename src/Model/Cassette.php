<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Model;

use Countable;
use InvalidArgumentException;
use OutOfBoundsException;

final readonly class Cassette implements Countable
{
    /**
     * @var list<Exchange>
     */
    private array $exchanges;

    /**
     * @param array<int, mixed> $exchanges
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private int $version = 1,
        array $exchanges = [],
        private array $metadata = [],
    ) {
        $validated = [];
        foreach ($exchanges as $exchange) {
            if (!$exchange instanceof Exchange) {
                throw new InvalidArgumentException(
                    sprintf('Cassette exchange must be an instance of %s', Exchange::class)
                );
            }
            $validated[] = $exchange;
        }

        $this->exchanges = $validated;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<Exchange>
     */
    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function count(): int
    {
        return count($this->exchanges);
    }

    public function isEmpty(): bool
    {
        return count($this->exchanges) === 0;
    }

    public function get(int $index): Exchange
    {
        return $this->exchanges[$index] ?? throw new OutOfBoundsException(
            sprintf('Exchange index %d does not exist in cassette.', $index)
        );
    }
}
