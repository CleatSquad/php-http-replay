<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\ExchangeSelectionResult;
use Psr\Http\Message\RequestInterface;

interface ExchangeSelectorInterface
{
    /**
     * Selects an exchange candidate from the cassette for a live request.
     *
     * @param RequestInterface $request Incoming HTTP request
     * @param list<Exchange> $exchanges All recorded exchanges in cassette
     * @param array<int, bool> $consumedIndices 0-based exchange indices already consumed in this session
     * @param RequestMatcherInterface $matcher Request matcher instance
     */
    public function select(
        RequestInterface $request,
        array $exchanges,
        array $consumedIndices,
        RequestMatcherInterface $matcher
    ): ExchangeSelectionResult;
}
