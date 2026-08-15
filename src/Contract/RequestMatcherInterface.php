<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Contract;

use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\MatchResult;
use Psr\Http\Message\RequestInterface;

/**
 * Decides whether a live request is the one a recorded exchange answers.
 *
 * The verdict is a MatchResult and not a bool so a failed replay can explain
 * itself; implementations are expected to report every difference they found,
 * not to stop at the first one.
 */
interface RequestMatcherInterface
{
    public function match(RequestInterface $request, Exchange $recorded): MatchResult;
}
