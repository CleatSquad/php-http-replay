<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Engine;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\UnorderedMismatchException;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpReplayEngine::class)]
final class ExchangeExpirationTest extends TestCase
{
    public function testExpiredExchangeIsNotServedInReplayAcA1(): void
    {
        $store = new InMemoryCassetteStore();
        $now = time();

        $oldRequest = new Request('POST', 'https://api.example.com/v1/chat', ['Content-Type' => 'application/json'], '{"prompt":"old"}');
        $oldResponse = new Response(200, [], '{"reply":"old_reply"}');
        $oldExchange = new Exchange($oldRequest, $oldResponse, recordedAt: $now - 7200); // 2 hours old

        $freshRequest = new Request('POST', 'https://api.example.com/v1/chat', ['Content-Type' => 'application/json'], '{"prompt":"fresh"}');
        $freshResponse = new Response(200, [], '{"reply":"fresh_reply"}');
        $freshExchange = new Exchange($freshRequest, $freshResponse, recordedAt: $now - 60); // 1 minute old

        // Cassette has 1 expired exchange and 1 fresh exchange
        $store->save('test_cassette', new Cassette(2, [$oldExchange, $freshExchange]));

        // Engine configured with TTL = 3600 seconds (1 hour)
        $engine = new HttpReplayEngine(
            mode: ExecutionMode::Replay,
            cassetteStore: $store,
            cassetteName: 'test_cassette',
            requestMatcher: new DefaultRequestMatcher(),
            sanitizer: new DefaultSanitizer(),
            realClient: null,
            matchingMode: ExecutionMatchingMode::Unordered,
            exchangeTtl: 3600,
        );

        // Fresh request matches and replays successfully
        $res = $engine->sendRequest($freshRequest);
        $this->assertSame('{"reply":"fresh_reply"}', (string) $res->getBody());

        // Old request fails with mismatch because its exchange is expired (> 3600s)
        $this->expectException(UnorderedMismatchException::class);
        $engine->sendRequest($oldRequest);
    }
}
