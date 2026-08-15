<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Storage;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Exception\InvalidCassetteException;
use CleatSquad\HttpReplay\Internal\DecodedValue;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class JsonCassetteStore implements CassetteStoreInterface
{
    public const CURRENT_SCHEMA_VERSION = 2;

    public function __construct(
        private string $baseDir,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?ResponseFactoryInterface $responseFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
    ) {
    }

    public function exists(string $name): bool
    {
        return is_file($this->getFilePath($name));
    }

    public function load(string $name): ?Cassette
    {
        $filePath = $this->getFilePath($name);
        if (!is_file($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw InvalidCassetteException::malformed($filePath, 'Could not read cassette file.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidCassetteException::malformed($filePath, 'Invalid JSON syntax: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw InvalidCassetteException::malformed($filePath, 'Cassette root must be a JSON object.');
        }

        $version = $decoded['version'] ?? null;
        if (!is_int($version)) {
            throw InvalidCassetteException::malformed($filePath, 'Missing or invalid "version" integer field.');
        }

        if ($version !== 1 && $version !== self::CURRENT_SCHEMA_VERSION) {
            throw InvalidCassetteException::unsupportedVersion($filePath, $version, self::CURRENT_SCHEMA_VERSION);
        }

        $exchangesData = $decoded['exchanges'] ?? null;
        if (!is_array($exchangesData)) {
            throw InvalidCassetteException::malformed($filePath, 'Missing or invalid "exchanges" array field.');
        }

        $metadata = DecodedValue::asStringKeyedMap($decoded['metadata'] ?? null);

        // Verify cassette integrity checksum if present in metadata. The checksum is
        // stored in the file it covers and is not signed, so it detects corruption
        // and accidental edits, not deliberate tampering.
        $checksumVal = $metadata['checksum'] ?? null;
        if (is_string($checksumVal) && str_starts_with($checksumVal, 'sha256:')) {
            $expectedHash = substr($checksumVal, 7);
            $actualHash = hash('sha256', (string) json_encode($exchangesData));
            if ($expectedHash !== $actualHash) {
                throw InvalidCassetteException::malformed($filePath, 'Cassette checksum mismatch. File content does not match its recorded checksum.');
            }
        }

        $exchanges = [];
        foreach ($exchangesData as $index => $item) {
            if (!is_array($item)) {
                throw InvalidCassetteException::malformed($filePath, sprintf('Exchange at index %d must be an object.', $index));
            }
            $exchanges[] = $this->deserializeExchange($item, $filePath, $index);
        }

        return new Cassette($version, $exchanges, $metadata);
    }

    public function save(string $name, Cassette $cassette): void
    {
        $filePath = $this->getFilePath($name);
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" could not be created', $dir));
            }
        }

        $serializedExchanges = [];
        foreach ($cassette->exchanges() as $exchange) {
            $serializedExchanges[] = $this->serializeExchange($exchange);
        }

        // The checksum is derived from the exchanges, never carried over: a cassette
        // reloaded, appended to and saved again would otherwise keep the checksum of
        // its previous content and fail verification on the next load.
        $metadata = $cassette->metadata();
        $metadata['checksum'] = 'sha256:' . hash('sha256', (string) json_encode($serializedExchanges));

        $payload = [
            'version' => $cassette->version(),
            'metadata' => $metadata,
            'exchanges' => $serializedExchanges,
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to serialize cassette to JSON: ' . $e->getMessage(), 0, $e);
        }

        // Atomic write via temp file with lock protection against race conditions
        $lockPath = $filePath . '.lock';
        $lockFp = fopen($lockPath, 'c+');
        if ($lockFp !== false) {
            flock($lockFp, \LOCK_EX);
        }

        try {
            $tmpFile = $filePath . '.' . uniqid('tmp_', true);
            if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Failed to write temporary cassette file "%s"', $tmpFile));
            }

            if (!rename($tmpFile, $filePath)) {
                @unlink($tmpFile);
                throw new \RuntimeException(sprintf('Failed to atomically rename cassette file to "%s"', $filePath));
            }
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, \LOCK_UN);
                fclose($lockFp);
                @unlink($lockPath);
            }
        }
    }

    private function getFilePath(string $name): string
    {
        $safeName = basename($name);
        if (!str_ends_with($safeName, '.json')) {
            $safeName .= '.json';
        }
        return rtrim($this->baseDir, '/') . '/' . $safeName;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function deserializeExchange(array $data, string $filePath, int $index): Exchange
    {
        $reqData = $data['request'] ?? null;
        $resData = $data['response'] ?? null;

        if (!is_array($reqData) || !is_array($resData)) {
            throw InvalidCassetteException::malformed($filePath, sprintf('Exchange at index %d requires "request" and "response" objects.', $index));
        }

        $method = DecodedValue::asString($reqData['method'] ?? null);
        $uri = DecodedValue::asString($reqData['uri'] ?? null);
        $reqBody = DecodedValue::asString($reqData['body'] ?? null);

        if ($method === '' || $uri === '') {
            throw InvalidCassetteException::malformed($filePath, sprintf('Exchange request at index %d requires non-empty "method" and "uri".', $index));
        }

        $status = DecodedValue::asInt($resData['status'] ?? null);
        $resBody = DecodedValue::asString($resData['body'] ?? null);

        if ($status < 100 || $status > 599) {
            throw InvalidCassetteException::malformed($filePath, sprintf('Exchange response at index %d requires a valid HTTP status code.', $index));
        }

        $cleanReqHeaders = DecodedValue::asHeaders($reqData['headers'] ?? null);
        $cleanResHeaders = DecodedValue::asHeaders($resData['headers'] ?? null);

        // Construct Request
        if ($this->requestFactory !== null && $this->streamFactory !== null) {
            $request = $this->requestFactory->createRequest($method, $uri);
            foreach ($cleanReqHeaders as $name => $vals) {
                $request = $request->withHeader($name, $vals);
            }
            if ($reqBody !== '') {
                $request = $request->withBody($this->streamFactory->createStream($reqBody));
            }
        } else {
            $request = new Request($method, $uri, $cleanReqHeaders, $reqBody);
        }

        // Construct Response
        if ($this->responseFactory !== null && $this->streamFactory !== null) {
            $response = $this->responseFactory->createResponse($status);
            foreach ($cleanResHeaders as $name => $vals) {
                $response = $response->withHeader($name, $vals);
            }
            if ($resBody !== '') {
                $response = $response->withBody($this->streamFactory->createStream($resBody));
            }
        } else {
            $response = new Response($status, $cleanResHeaders, $resBody);
        }

        return new Exchange($request, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExchange(Exchange $exchange): array
    {
        $req = $exchange->request();
        $res = $exchange->response();

        $reqBodyStr = (string) $req->getBody();
        if ($req->getBody()->isSeekable()) {
            $req->getBody()->rewind();
        }

        $resBodyStr = (string) $res->getBody();
        if ($res->getBody()->isSeekable()) {
            $res->getBody()->rewind();
        }

        return [
            'request' => [
                'method' => $req->getMethod(),
                'uri' => (string) $req->getUri(),
                'headers' => $req->getHeaders(),
                'body' => $reqBodyStr,
            ],
            'response' => [
                'status' => $res->getStatusCode(),
                'headers' => $res->getHeaders(),
                'body' => $resBodyStr,
            ],
        ];
    }
}
