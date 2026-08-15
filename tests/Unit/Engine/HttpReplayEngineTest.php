<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Engine;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\CassetteNotFoundException;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(HttpReplayEngine::class)]
final class HttpReplayEngineTest extends TestCase
{
    private DefaultRequestMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DefaultRequestMatcher();
    }

    public function testPassthroughMode(): void
    {
        $realResponse = new Response(200, [], 'real output');
        $realClient = $this->createMock(ClientInterface::class);
        $realClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($realResponse);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->never())->method('load');
        $store->expects($this->never())->method('save');

        $sanitizer = $this->createMock(SanitizerInterface::class);

        $engine = new HttpReplayEngine(
            ExecutionMode::Passthrough,
            $store,
            'test_cassette',
            $this->matcher,
            $sanitizer,
            $realClient
        );

        $request = new Request('GET', 'https://api.example.com');
        $response = $engine->sendRequest($request);

        $this->assertSame($realResponse, $response);
    }

    public function testReplayModeSuccessAndExhaustion(): void
    {
        $req1 = new Request('GET', 'https://api.example.com/1');
        $res1 = new Response(200, [], 'res1');

        $req2 = new Request('POST', 'https://api.example.com/2');
        $res2 = new Response(201, [], 'res2');

        $cassette = new Cassette(1, [
            new Exchange($req1, $res1),
            new Exchange($req2, $res2),
        ]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->atLeastOnce())
            ->method('load')
            ->with('my_cassette')
            ->willReturn($cassette);

        $sanitizer = $this->createMock(SanitizerInterface::class);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'my_cassette',
            $this->matcher,
            $sanitizer
        );

        // Replay exchange #0
        $actualRes1 = $engine->sendRequest(new Request('GET', 'https://api.example.com/1'));
        $this->assertSame($res1, $actualRes1);

        // Replay exchange #1
        $actualRes2 = $engine->sendRequest(new Request('POST', 'https://api.example.com/2'));
        $this->assertSame($res2, $actualRes2);

        // Replay exchange #2 (exhausted)
        $this->expectException(RequestMismatchException::class);
        $engine->sendRequest(new Request('GET', 'https://api.example.com/3'));
    }

    public function testReplayModeMissingCassette(): void
    {
        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())
            ->method('load')
            ->with('missing')
            ->willReturn(null);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'missing',
            $this->matcher,
            $this->createMock(SanitizerInterface::class)
        );

        $this->expectException(CassetteNotFoundException::class);
        $engine->sendRequest(new Request('GET', 'https://api.example.com'));
    }

    public function testReplayModeMismatch(): void
    {
        $req1 = new Request('GET', 'https://api.example.com/1');
        $res1 = new Response(200, [], 'res1');
        $cassette = new Cassette(1, [new Exchange($req1, $res1)]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())
            ->method('load')
            ->willReturn($cassette);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'my_cassette',
            $this->matcher,
            $this->createMock(SanitizerInterface::class)
        );

        $this->expectException(RequestMismatchException::class);
        $engine->sendRequest(new Request('POST', 'https://api.example.com/1'));
    }

    public function testRecordModeAppendsAndSanitizesOnlyForCassette(): void
    {
        $realRequest = new Request('POST', 'https://api.example.com', ['Authorization' => 'Bearer REAL_SECRET']);
        $realResponse = new Response(200, ['Set-Cookie' => 'REAL_COOKIE'], 'real body');

        $sanitizedRequest = new Request('POST', 'https://api.example.com', ['Authorization' => 'Bearer [REDACTED]']);
        $sanitizedResponse = new Response(200, ['Set-Cookie' => '[REDACTED]'], 'real body');

        // Fake/real client verification
        $realClient = new class($realResponse) implements ClientInterface {
            public ?RequestInterface $receivedRequest = null;
            public function __construct(
                private ResponseInterface $responseToReturn,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->receivedRequest = $request;
                return $this->responseToReturn;
            }
        };

        $sanitizer = $this->createMock(SanitizerInterface::class);
        $sanitizer->expects($this->once())
            ->method('sanitizeRequest')
            ->with($realRequest)
            ->willReturn($sanitizedRequest);
        $sanitizer->expects($this->once())
            ->method('sanitizeResponse')
            ->with($realResponse)
            ->willReturn($sanitizedResponse);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())
            ->method('load')
            ->with('rec_cassette')
            ->willReturn(null);

        $savedCassetteContainer = new \stdClass();
        $store->expects($this->once())
            ->method('save')
            ->with('rec_cassette', $this->callback(function (Cassette $cassette) use ($savedCassetteContainer) {
                $savedCassetteContainer->cassette = $cassette;
                return true;
            }));

        $engine = new HttpReplayEngine(
            ExecutionMode::Record,
            $store,
            'rec_cassette',
            $this->matcher,
            $sanitizer,
            $realClient
        );

        $returnedResponse = $engine->sendRequest($realRequest);

        // Security Invariant Assertions:
        // 1. Real client received UNMUTATED original request with REAL_SECRET
        $this->assertSame($realRequest, $realClient->receivedRequest);
        $this->assertSame('Bearer REAL_SECRET', $realClient->receivedRequest->getHeaderLine('Authorization'));

        // 2. Caller receives UNMUTATED original response with REAL_COOKIE
        $this->assertSame($realResponse, $returnedResponse);
        $this->assertSame('REAL_COOKIE', $returnedResponse->getHeaderLine('Set-Cookie'));

        // 3. Persisted cassette contains SANITIZED exchange
        /** @var Cassette $savedCassette */
        $savedCassette = $savedCassetteContainer->cassette;
        $this->assertCount(1, $savedCassette);
        $persistedExchange = $savedCassette->get(0);

        $this->assertSame('Bearer [REDACTED]', $persistedExchange->request()->getHeaderLine('Authorization'));
        $this->assertSame('[REDACTED]', $persistedExchange->response()->getHeaderLine('Set-Cookie'));
    }

    public function testConstructorFailsEarlyIfRealClientNullInRecordMode(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Real HTTP client (PSR-18 ClientInterface) is required for record mode.');

        new HttpReplayEngine(
            ExecutionMode::Record,
            $this->createMock(CassetteStoreInterface::class),
            'cassette',
            $this->matcher,
            $this->createMock(SanitizerInterface::class),
            null
        );
    }

    public function testConstructorFailsEarlyIfRealClientNullInPassthroughMode(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Real HTTP client (PSR-18 ClientInterface) is required for passthrough mode.');

        new HttpReplayEngine(
            ExecutionMode::Passthrough,
            $this->createMock(CassetteStoreInterface::class),
            'cassette',
            $this->matcher,
            $this->createMock(SanitizerInterface::class),
            null
        );
    }
}
