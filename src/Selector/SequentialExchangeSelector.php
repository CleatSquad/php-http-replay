<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Selector;

use CleatSquad\HttpReplay\Contract\ExchangeSelectorInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\ExchangeSelectionResult;
use CleatSquad\HttpReplay\Model\MatchResult;
use Psr\Http\Message\RequestInterface;

final class SequentialExchangeSelector implements ExchangeSelectorInterface
{
    public function select(
        RequestInterface $request,
        array $exchanges,
        array $consumedIndices,
        RequestMatcherInterface $matcher
    ): ExchangeSelectionResult {
        // Next sequential index is equal to the number of consumed exchanges in sequential mode
        $currentIndex = count($consumedIndices);
        $totalExchanges = count($exchanges);

        if (!array_key_exists($currentIndex, $exchanges)) {
            $differences = ['index' => sprintf('Cassette sequence exhausted: index %d requested, total exchanges: %d', $currentIndex, $totalExchanges)];
            return ExchangeSelectionResult::exhausted($currentIndex, MatchResult::mismatch($differences));
        }

        $recordedExchange = $exchanges[$currentIndex];
        $matchResult = $matcher->match($request, $recordedExchange);

        if ($matchResult->matched()) {
            return ExchangeSelectionResult::match($currentIndex, $recordedExchange, $matchResult, 1);
        }

        return ExchangeSelectionResult::mismatch($currentIndex, $matchResult, 1);
    }
}
