<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Integration\Guzzle;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Exception\RequestMismatchException;
use CleatSquad\HttpReplay\Integration\Guzzle\GuzzleOptionsAwareClient;
use CleatSquad\HttpReplay\Integration\Guzzle\GuzzleReplayHandler;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Tests\Support\DecodesJsonBody;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GuzzleReplayHandler::class)]
final class GuzzleReplayHandlerTest extends TestCase
{
    use DecodesJsonBody;

    public function testGuzzleReplayModeSuccess(): void
    {
        $recReq = new Request('GET', 'https://api.openai.com/v1/models');
        $recRes = new Response(200, ['Content-Type' => 'application/json'], '{"data":[{"id":"gpt-4"}]}');
        $cassette = new Cassette(1, [new Exchange($recReq, $recRes)]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->with('guzzle_test')->willReturn($cassette);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'guzzle_test',
            new DefaultRequestMatcher(),
            new DefaultSanitizer()
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $response = $client->get('https://api.openai.com/v1/models');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"data":[{"id":"gpt-4"}]}', (string) $response->getBody());
    }

    public function testGuzzleRecordModeSecurityAndSanitization(): void
    {
        $realReq = new Request('POST', 'https://api.example.com/v1/data', ['Authorization' => 'Bearer REAL_API_KEY'], '{"secret":"SECRET_PAYLOAD"}');
        $realRes = new Response(200, ['Set-Cookie' => 'REAL_COOKIE'], '{"result":"ok"}');

        $realClient = new class($realRes) implements ClientInterface {
            public ?RequestInterface $received = null;
            public function __construct(private ResponseInterface $responseToReturn) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->received = $request;
                return $this->responseToReturn;
            }
        };

        $storeContainer = new \stdClass();
        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->willReturn(null);
        $store->expects($this->once())->method('save')->with('guzzle_rec', $this->callback(function (Cassette $c) use ($storeContainer) {
            $storeContainer->cassette = $c;
            return true;
        }));

        $engine = new HttpReplayEngine(
            ExecutionMode::Record,
            $store,
            'guzzle_rec',
            new DefaultRequestMatcher(),
            new DefaultSanitizer(),
            $realClient
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $response = $client->post('https://api.example.com/v1/data', [
            'headers' => ['Authorization' => 'Bearer REAL_API_KEY'],
            'body' => '{"secret":"SECRET_PAYLOAD"}',
        ]);

        // Live transport assertions
        $this->assertSame('Bearer REAL_API_KEY', $realClient->received?->getHeaderLine('Authorization'));
        $this->assertSame('REAL_COOKIE', $response->getHeaderLine('Set-Cookie'));

        // Persisted cassette assertions
        /** @var Cassette $savedCassette */
        $savedCassette = $storeContainer->cassette;
        $ex = $savedCassette->get(0);
        $this->assertSame('Bearer [REDACTED]', $ex->request()->getHeaderLine('Authorization'));
        $reqJson = self::decodeJsonObject((string) $ex->request()->getBody());
        $this->assertSame('[REDACTED]', $reqJson['secret']);
        $this->assertSame('[REDACTED]', $ex->response()->getHeaderLine('Set-Cookie'));
    }

    public function testGuzzleRecordModeForwardsPerRequestTimeoutToTheRealClient(): void
    {
        $realRes = new Response(200, [], '{"result":"ok"}');
        $fakeGuzzleClient = new class($realRes) implements \GuzzleHttp\ClientInterface {
            /** @var array<string, mixed>|null */
            public ?array $receivedOptions = null;

            public function __construct(private ResponseInterface $responseToReturn)
            {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function send(RequestInterface $request, array $options = []): ResponseInterface
            {
                $this->receivedOptions = $options;

                return $this->responseToReturn;
            }

            /**
             * @param array<string, mixed> $options
             * @return \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed>
             */
            public function sendAsync(RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                /** @var \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed> */
                return \GuzzleHttp\Promise\Create::promiseFor($this->send($request, $options));
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, $uri = '', array $options = []): ResponseInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            /**
             * @param array<string, mixed> $options
             * @return \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed>
             */
            public function requestAsync(string $method, $uri = '', array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            public function getConfig(?string $option = null): mixed
            {
                throw new \LogicException('Not used by this test.');
            }
        };

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->willReturn(null);
        $store->expects($this->once())->method('save');

        $engine = new HttpReplayEngine(
            ExecutionMode::Record,
            $store,
            'guzzle_timeout',
            new DefaultRequestMatcher(),
            new DefaultSanitizer(),
            new GuzzleOptionsAwareClient($fakeGuzzleClient)
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $client->post('https://api.example.com/v1/data', ['timeout' => 20.0]);

        $this->assertSame(20.0, $fakeGuzzleClient->receivedOptions['timeout'] ?? null);
    }

    public function testGuzzleRecordModeNeverForwardsTheStreamOption(): void
    {
        $realRes = new Response(200, [], '{"result":"ok"}');
        $fakeGuzzleClient = new class($realRes) implements \GuzzleHttp\ClientInterface {
            /** @var array<string, mixed>|null */
            public ?array $receivedOptions = null;

            public function __construct(private ResponseInterface $responseToReturn)
            {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function send(RequestInterface $request, array $options = []): ResponseInterface
            {
                $this->receivedOptions = $options;

                return $this->responseToReturn;
            }

            /**
             * @param array<string, mixed> $options
             * @return \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed>
             */
            public function sendAsync(RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                /** @var \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed> */
                return \GuzzleHttp\Promise\Create::promiseFor($this->send($request, $options));
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, $uri = '', array $options = []): ResponseInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            /**
             * @param array<string, mixed> $options
             * @return \GuzzleHttp\Promise\PromiseInterface<ResponseInterface, mixed>
             */
            public function requestAsync(string $method, $uri = '', array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            public function getConfig(?string $option = null): mixed
            {
                throw new \LogicException('Not used by this test.');
            }
        };

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->willReturn(null);
        $store->expects($this->once())->method('save');

        $engine = new HttpReplayEngine(
            ExecutionMode::Record,
            $store,
            'guzzle_stream',
            new DefaultRequestMatcher(),
            new DefaultSanitizer(),
            new GuzzleOptionsAwareClient($fakeGuzzleClient)
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $client->post('https://api.example.com/v1/data', ['timeout' => 20.0, 'stream' => true, 'read_timeout' => 20.0]);

        $this->assertArrayNotHasKey('stream', $fakeGuzzleClient->receivedOptions ?? []);
        $this->assertArrayNotHasKey('read_timeout', $fakeGuzzleClient->receivedOptions ?? []);
        $this->assertSame(20.0, $fakeGuzzleClient->receivedOptions['timeout'] ?? null);
    }

    public function testGuzzleMismatchThrowsException(): void
    {
        $recReq = new Request('GET', 'https://api.openai.com/v1/models');
        $recRes = new Response(200, [], 'ok');
        $cassette = new Cassette(1, [new Exchange($recReq, $recRes)]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->willReturn($cassette);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'guzzle_test',
            new DefaultRequestMatcher(),
            new DefaultSanitizer()
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $this->expectException(RequestMismatchException::class);
        $client->post('https://api.openai.com/v1/models');
    }

    public function testGuzzleAsyncRequestFulfillsPromiseSynchronously(): void
    {
        $recReq = new Request('GET', 'https://api.example.test/async');
        $recRes = new Response(200, [], 'async body');
        $cassette = new Cassette(1, [new Exchange($recReq, $recRes)]);

        $store = $this->createMock(CassetteStoreInterface::class);
        $store->expects($this->once())->method('load')->willReturn($cassette);

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'guzzle_async',
            new DefaultRequestMatcher(),
            new DefaultSanitizer()
        );

        $handler = new GuzzleReplayHandler($engine);
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $promise = $client->getAsync('https://api.example.test/async');

        $response = $promise->wait();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('async body', (string) $response->getBody());
    }
}
