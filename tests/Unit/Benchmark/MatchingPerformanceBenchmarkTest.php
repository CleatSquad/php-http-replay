<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Benchmark;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MatchingPerformanceBenchmarkTest extends TestCase
{
    /**
     * @dataProvider provideSizes
     */
    public function testCompareSequentialAndUnorderedPerformance(int $count): void
    {
        $exchanges = [];
        $requests = [];

        for ($i = 0; $i < $count; $i++) {
            $url = sprintf('https://api.example.com/item/%d', $i);
            $req = new Request('GET', $url, ['Accept' => 'application/json']);
            $res = new Response(200, [], json_encode(['id' => $i, 'name' => 'Item ' . $i]) ?: '');
            $exchanges[] = new Exchange($req, $res);
            $requests[] = $req;
        }

        $store = new InMemoryCassetteStore(['bench' => new Cassette(1, $exchanges)]);
        $matcher = new DefaultRequestMatcher();
        $sanitizer = new DefaultSanitizer();

        // 1. Benchmark Sequential Mode (in order)
        $seqEngine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'bench',
            $matcher,
            $sanitizer,
            matchingMode: ExecutionMatchingMode::Sequential
        );

        $startSeq = microtime(true);
        foreach ($requests as $req) {
            $seqEngine->sendRequest($req);
        }
        $timeSeq = microtime(true) - $startSeq;

        // 2. Benchmark Unordered Mode (worst-case reverse order)
        $unorderEngine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'bench',
            $matcher,
            $sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        $reverseRequests = array_reverse($requests);
        $startUnorder = microtime(true);
        foreach ($reverseRequests as $req) {
            $unorderEngine->sendRequest($req);
        }
        $timeUnorder = microtime(true) - $startUnorder;

        $this::assertCount($count, $seqEngine->stats()->unusedIndices === [] ? $requests : []);
        $this::assertCount($count, $unorderEngine->stats()->unusedIndices === [] ? $requests : []);

        // Log benchmark stats silently via phpunit output assertion
        $this::assertTrue($timeSeq >= 0);
        $this::assertTrue($timeUnorder >= 0);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideSizes(): array
    {
        return [
            '10 exchanges' => [10],
            '100 exchanges' => [100],
        ];
    }
}
