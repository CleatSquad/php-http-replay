<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Naming;

use CleatSquad\HttpReplay\Contract\CassetteNamingStrategyInterface;
use Closure;

final readonly class CallbackCassetteNamingStrategy implements CassetteNamingStrategyInterface
{
    private Closure $callback;

    /**
     * @param callable(): string $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function name(): string
    {
        return ($this->callback)();
    }
}
