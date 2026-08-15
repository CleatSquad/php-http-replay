<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Engine;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\SequenceExhaustedException;
use CleatSquad\HttpReplay\Exception\SequenceMismatchException;
use CleatSquad\HttpReplay\Exception\UnorderedMismatchException;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UnorderedMatchingTest extends TestCase
{
    private InMemoryCassetteStore $store;
    private DefaultRequestMatcher $matcher;
    private DefaultSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->store = new InMemoryCassetteStore();
        $this->matcher = new DefaultRequestMatcher();
        $this->sanitizer = new DefaultSanitizer();
    }

    public function testDefaultMatchingModeIsSequential(): void
    {
        $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/a'), new Response(200, [], 'Resp A'));
        $exchange2 = new Exchange(new Request('GET', 'https://api.example.com/b'), new Response(200, [], 'Resp B'));
        $this->store->save('test_cassette', new Cassette(1, [$exchange1, $exchange2]));

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $this->store,
            'test_cassette',
            $this->matcher,
            $this->sanitizer
        );

        $this::assertSame(ExecutionMatchingMode::Sequential, $engine->stats()->matchingMode);

        // Sending request 'b' first when expected 'a' must throw SequenceMismatchException in Sequential mode
        $this->expectException(SequenceMismatchException::class);
        $engine->sendRequest(new Request('GET', 'https://api.example.com/b'));
    }

    public function testUnorderedMatchingReplaysOutofOrderRequests(): void
    {
        $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/users'), new Response(200, [], 'Users'));
        $exchange2 = new Exchange(new Request('GET', 'https://api.example.com/posts'), new Response(200, [], 'Posts'));
        $exchange3 = new Exchange(new Request('GET', 'https://api.example.com/comments'), new Response(200, [], 'Comments'));
        $this->store->save('test_cassette', new Cassette(1, [$exchange1, $exchange2, $exchange3]));

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $this->store,
            'test_cassette',
            $this->matcher,
            $this->sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        // Request posts first (index 1)
        $resPosts = $engine->sendRequest(new Request('GET', 'https://api.example.com/posts'));
        $this::assertSame('Posts', (string) $resPosts->getBody());

        // Request users second (index 0)
        $resUsers = $engine->sendRequest(new Request('GET', 'https://api.example.com/users'));
        $this::assertSame('Users', (string) $resUsers->getBody());

        // Request comments third (index 2)
        $resComments = $engine->sendRequest(new Request('GET', 'https://api.example.com/comments'));
        $this::assertSame('Comments', (string) $resComments->getBody());

        $stats = $engine->stats();
        $this::assertSame(3, $stats->replayedCount);
        $this::assertTrue($stats->isFullyConsumed());
    }

    public function testConsumedExchangeCannotBeReused(): void
    {
        $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/item'), new Response(200, [], 'Item 1'));
        $this->store->save('test_cassette', new Cassette(1, [$exchange1]));

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $this->store,
            'test_cassette',
            $this->matcher,
            $this->sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        // First call succeeds
        $res1 = $engine->sendRequest(new Request('GET', 'https://api.example.com/item'));
        $this::assertSame('Item 1', (string) $res1->getBody());

        // Second call fails because exchange is already consumed
        $this->expectException(SequenceExhaustedException::class);
        $engine->sendRequest(new Request('GET', 'https://api.example.com/item'));
    }

    public function testTwoIdenticalRequestsWithDifferentResponsesConsumedInOrder(): void
    {
        $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/status'), new Response(200, [], 'Pending'));
        $exchange2 = new Exchange(new Request('GET', 'https://api.example.com/status'), new Response(200, [], 'Completed'));
        $this->store->save('test_cassette', new Cassette(1, [$exchange1, $exchange2]));

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $this->store,
            'test_cassette',
            $this->matcher,
            $this->sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        $res1 = $engine->sendRequest(new Request('GET', 'https://api.example.com/status'));
        $this::assertSame('Pending', (string) $res1->getBody());

        $res2 = $engine->sendRequest(new Request('GET', 'https://api.example.com/status'));
        $this::assertSame('Completed', (string) $res2->getBody());
    }

    public function testNonExistentRequestProducesUnorderedMismatchException(): void
    {
        $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/users'), new Response(200, [], 'Users'));
        $this->store->save('test_cassette', new Cassette(1, [$exchange1]));

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $this->store,
            'test_cassette',
            $this->matcher,
            $this->sanitizer,
            matchingMode: ExecutionMatchingMode::Unordered
        );

        try {
            $engine->sendRequest(new Request('GET', 'https://api.example.com/missing'));
            $this::fail('Expected UnorderedMismatchException was not thrown');
        } catch (UnorderedMismatchException $e) {
            $this::assertSame('test_cassette', $e->cassetteName());
            $this::assertSame(1, $e->inspectedCount());
            $this::assertSame(0, $e->consumedCount());
            $this::assertSame(0, $e->bestCandidateIndex());
            $this::assertStringContainsString('Unordered request mismatch', $e->toCliString());
        }
    }

    public function testJsonCassetteStoreWorksWithUnorderedMatching(): void
    {
        $tempDir = sys_get_temp_dir() . '/http_replay_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $jsonStore = new JsonCassetteStore($tempDir);
            $exchange1 = new Exchange(new Request('GET', 'https://api.example.com/first'), new Response(200, [], 'First'));
            $exchange2 = new Exchange(new Request('GET', 'https://api.example.com/second'), new Response(200, [], 'Second'));
            $jsonStore->save('json_cassette', new Cassette(1, [$exchange1, $exchange2]));

            $engine = new HttpReplayEngine(
                ExecutionMode::Replay,
                $jsonStore,
                'json_cassette',
                $this->matcher,
                $this->sanitizer,
                matchingMode: ExecutionMatchingMode::Unordered
            );

            $resSecond = $engine->sendRequest(new Request('GET', 'https://api.example.com/second'));
            $this::assertSame('Second', (string) $resSecond->getBody());

            $resFirst = $engine->sendRequest(new Request('GET', 'https://api.example.com/first'));
            $this::assertSame('First', (string) $resFirst->getBody());
        } finally {
            array_map('unlink', glob($tempDir . '/*') ?: []);
            rmdir($tempDir);
        }
    }
}
