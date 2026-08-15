<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Engine;

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnorderedMatchingAtScaleTest extends TestCase
{
    #[DataProvider('provideSizes')]
    public function testSequentialAndUnorderedBothConsumeTheWholeCassette(int $count): void
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

        // Sequential mode: requests arrive in recorded order.
        $seqEngine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'bench',
            $matcher,
            $sanitizer,
            matchingMode: ExecutionMatchingMode::Sequential
        );

        foreach ($requests as $req) {
            $seqEngine->sendRequest($req);
        }

        // Unordered mode: requests arrive reversed.
        $unorderEngine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'bench',
            $matcher,
            $sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        // Worst case for unordered matching: every request matches the last
        // unconsumed exchange, so each lookup scans the whole remaining set.
        foreach (array_reverse($requests) as $req) {
            $unorderEngine->sendRequest($req);
        }

        $seqStats = $seqEngine->stats();
        $this->assertSame($count, $seqStats->replayedCount);
        $this->assertSame([], $seqStats->unusedIndices);

        $unorderStats = $unorderEngine->stats();
        $this->assertSame($count, $unorderStats->replayedCount);
        $this->assertSame([], $unorderStats->unusedIndices);
        $this->assertSame(ExecutionMatchingMode::Unordered, $unorderStats->matchingMode);
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
