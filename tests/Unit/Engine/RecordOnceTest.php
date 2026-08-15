<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Engine;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

final class RecordOnceTest extends TestCase
{
    private DefaultRequestMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DefaultRequestMatcher();
    }

    public function testRecordOnceReplaysWhenCassetteMatches(): void
    {
        $req = new Request('GET', 'https://api.example.com/data');
        $res = new Response(200, [], '{"status":"ok"}');
        $cassette = new Cassette(1, [new Exchange($req, $res)]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())
            ->method('load')
            ->with('test_cassette')
            ->willReturn($cassette);

        $sanitizer = $this->createMock(SanitizerInterface::class);

        $engine = new HttpReplayEngine(
            ExecutionMode::RecordOnce,
            $store,
            'test_cassette',
            $this->matcher,
            $sanitizer,
            null
        );

        $response = $engine->sendRequest($req);
        $this->assertSame($res, $response);
    }

    public function testRecordOnceRecordsWhenCassetteMissing(): void
    {
        $realReq = new Request('POST', 'https://api.example.com/data');
        $realRes = new Response(200, [], '{"recorded":true}');

        $realClient = $this->createMock(ClientInterface::class);
        $realClient->expects($this->once())
            ->method('sendRequest')
            ->with($realReq)
            ->willReturn($realRes);

        $sanitizer = $this->createMock(SanitizerInterface::class);
        $sanitizer->expects($this->once())
            ->method('sanitizeRequest')
            ->willReturn($realReq);
        $sanitizer->expects($this->once())
            ->method('sanitizeResponse')
            ->willReturn($realRes);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->atLeastOnce())
            ->method('load')
            ->with('new_cassette')
            ->willReturn(null);
        $store->expects($this->once())
            ->method('save')
            ->with('new_cassette', $this->isInstanceOf(Cassette::class));

        $engine = new HttpReplayEngine(
            ExecutionMode::RecordOnce,
            $store,
            'new_cassette',
            $this->matcher,
            $sanitizer,
            $realClient
        );

        $response = $engine->sendRequest($realReq);
        $this->assertSame($realRes, $response);
    }

    public function testRecordOnceFailsIfNoRealClientOnMiss(): void
    {
        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())
            ->method('load')
            ->willReturn(null);

        $engine = new HttpReplayEngine(
            ExecutionMode::RecordOnce,
            $store,
            'missing_cassette',
            $this->matcher,
            $this->createMock(SanitizerInterface::class),
            null
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Real HTTP client (PSR-18 ClientInterface) is required for RecordOnce mode when recording a missing exchange.');

        $engine->sendRequest(new Request('GET', 'https://api.example.com'));
    }

    public function testRecordOnceRecordsThenReplaysTheSameRequest(): void
    {
        $request = new Request('GET', 'https://api.example.com/data');
        $response = new Response(200, [], '{"recorded":true}');

        $realClient = $this->createMock(ClientInterface::class);
        $realClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);

        $sanitizer = $this->createMock(SanitizerInterface::class);
        $sanitizer->method('sanitizeRequest')->willReturn($request);
        $sanitizer->method('sanitizeResponse')->willReturn($response);

        $store = new InMemoryCassetteStore();

        $engine = new HttpReplayEngine(
            ExecutionMode::RecordOnce,
            $store,
            'round_trip',
            $this->matcher,
            $sanitizer,
            $realClient
        );

        $this->assertSame($response, $engine->sendRequest($request));

        // Second identical request: replayed from the cassette, no second real call.
        $this->assertSame($response, $engine->sendRequest($request));

        $cassette = $store->load('round_trip');
        $this->assertNotNull($cassette);
        $this->assertCount(1, $cassette->exchanges());
    }
}
