<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Selector;

use CleatSquad\HttpReplay\Contract\ExchangeSelectorInterface;
use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Model\ExchangeSelectionResult;
use CleatSquad\HttpReplay\Model\MatchResult;
use Psr\Http\Message\RequestInterface;

final class UnorderedExchangeSelector implements ExchangeSelectorInterface
{
    /** @var (\Closure(): int)|null */
    private ?\Closure $clock;

    /**
     * @param ?int $exchangeTtl Time-to-live in seconds for an exchange. Expired exchanges are ignored during selection.
     * @param ?(\Closure(): int) $clock Custom clock callable for testing.
     */
    public function __construct(
        private ?int $exchangeTtl = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock;
    }

    public function select(
        RequestInterface $request,
        array $exchanges,
        array $consumedIndices,
        RequestMatcherInterface $matcher
    ): ExchangeSelectionResult {
        $totalExchanges = count($exchanges);
        $consumedCount = count($consumedIndices);

        if ($consumedCount >= $totalExchanges) {
            $differences = ['index' => sprintf('Cassette sequence exhausted: %d consumed out of %d total exchanges', $consumedCount, $totalExchanges)];
            return ExchangeSelectionResult::exhausted($totalExchanges, MatchResult::mismatch($differences));
        }

        $now = $this->clock !== null ? ($this->clock)() : time();
        $inspectedCount = 0;
        $bestCandidateIndex = -1;
        $bestMatchResult = null;
        $fewestDiffCount = \PHP_INT_MAX;

        foreach ($exchanges as $i => $recordedExchange) {
            if (isset($consumedIndices[$i])) {
                continue;
            }

            if ($this->exchangeTtl !== null && $this->exchangeTtl > 0 && $recordedExchange->isExpired($this->exchangeTtl, $now)) {
                continue;
            }

            $inspectedCount++;
            $matchResult = $matcher->match($request, $recordedExchange);

            if ($matchResult->matched()) {
                return ExchangeSelectionResult::match($i, $recordedExchange, $matchResult, $inspectedCount);
            }

            $diffCount = count($matchResult->differences());
            if ($diffCount < $fewestDiffCount) {
                $fewestDiffCount = $diffCount;
                $bestCandidateIndex = $i;
                $bestMatchResult = $matchResult;
            }
        }

        if ($bestMatchResult === null) {
            $bestCandidateIndex = 0;
            $bestMatchResult = MatchResult::mismatch(['replay' => 'No unconsumed exchange candidate found in cassette.']);
        }

        return ExchangeSelectionResult::mismatch($bestCandidateIndex, $bestMatchResult, $inspectedCount);
    }
}
