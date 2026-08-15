<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Sanitizer;

use CleatSquad\HttpReplay\Contract\SanitizerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class DefaultSanitizer implements SanitizerInterface
{
    private const DEFAULT_SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'x-goog-api-key',
        'x-anthropic-api-key',
        'cookie',
        'set-cookie',
    ];

    private const DEFAULT_SENSITIVE_BODY_KEYS = [
        'api_key',
        'apikey',
        'secret',
        'password',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
    ];

    private const DEFAULT_SENSITIVE_QUERY_PARAMS = [
        'api_key',
        'apikey',
        'secret',
        'password',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'key',
    ];

    /**
     * @param list<string> $sensitiveHeaders Header names to mask (case-insensitive)
     * @param list<string> $sensitiveBodyKeys JSON object keys to mask recursively
     * @param list<string> $sensitiveQueryParams URI query parameter names to mask (case-insensitive)
     * @param string $replacement Masking string replacement
     * @param list<string> $sensitiveJsonPaths Explicit JSON paths to mask (dot notation, e.g. "$.user.profile.token"); pass it as a named argument
     */
    public function __construct(
        private readonly array $sensitiveHeaders = self::DEFAULT_SENSITIVE_HEADERS,
        private readonly array $sensitiveBodyKeys = self::DEFAULT_SENSITIVE_BODY_KEYS,
        private readonly array $sensitiveQueryParams = self::DEFAULT_SENSITIVE_QUERY_PARAMS,
        private readonly string $replacement = '[REDACTED]',
        private readonly ?StreamFactoryInterface $streamFactory = null,
        private readonly array $sensitiveJsonPaths = [],
    ) {
    }

    public function sanitizeRequest(RequestInterface $request): RequestInterface
    {
        $sanitized = $request;

        // 1. Sanitize URI Query Parameters
        $uri = $request->getUri();
        $query = $uri->getQuery();
        if ($query !== '') {
            $sanitizedQuery = $this->sanitizeQueryString($query);
            if ($sanitizedQuery !== $query) {
                $sanitized = $sanitized->withUri($uri->withQuery($sanitizedQuery));
            }
        }

        // 2. Sanitize Headers
        $sensitiveHeadersLower = array_map('strtolower', $this->sensitiveHeaders);
        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), $sensitiveHeadersLower, true)) {
                $maskedValues = array_map(function (string $val) {
                    if (str_starts_with(strtolower($val), 'bearer ')) {
                        return 'Bearer ' . $this->replacement;
                    }
                    if (str_starts_with(strtolower($val), 'basic ')) {
                        return 'Basic ' . $this->replacement;
                    }
                    return $this->replacement;
                }, $values);

                $sanitized = $sanitized->withHeader($name, $maskedValues);
            }
        }

        // 3. Sanitize JSON Body
        $bodyStr = (string) $request->getBody();
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        } elseif ($bodyStr !== '') {
            // Non-seekable stream: replace body with fresh seekable stream so downstream can consume it
            $sanitized = $sanitized->withBody($this->createStream($bodyStr, $request->getBody()));
        }

        if ($this->isValidJson($bodyStr)) {
            $data = json_decode($bodyStr, true);
            $sanitizedData = $this->sanitizeJsonData($data);
            $sanitizedData = $this->sanitizeJsonPaths($sanitizedData);
            $newBodyStr = (string) json_encode($sanitizedData);

            $sanitized = $sanitized->withBody($this->createStream($newBodyStr, $request->getBody()));
        }

        return $sanitized;
    }

    public function sanitizeResponse(ResponseInterface $response): ResponseInterface
    {
        $sanitized = $response;

        // 1. Sanitize Headers
        $sensitiveHeadersLower = array_map('strtolower', $this->sensitiveHeaders);
        foreach ($response->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), $sensitiveHeadersLower, true)) {
                $maskedValues = array_map(fn () => $this->replacement, $values);
                $sanitized = $sanitized->withHeader($name, $maskedValues);
            }
        }

        // 2. Sanitize JSON Body
        $bodyStr = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        } elseif ($bodyStr !== '') {
            // Non-seekable stream: replace body with fresh seekable stream so downstream can consume it
            $sanitized = $sanitized->withBody($this->createStream($bodyStr, $response->getBody()));
        }

        if ($this->isValidJson($bodyStr)) {
            $data = json_decode($bodyStr, true);
            $sanitizedData = $this->sanitizeJsonData($data);
            $sanitizedData = $this->sanitizeJsonPaths($sanitizedData);
            $newBodyStr = (string) json_encode($sanitizedData);

            $sanitized = $sanitized->withBody($this->createStream($newBodyStr, $response->getBody()));
        }

        return $sanitized;
    }

    private function isValidJson(string $string): bool
    {
        $trimmed = trim($string);
        if ($trimmed === '') {
            return false;
        }
        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function sanitizeJsonData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sanitized = [];
        $sensitiveKeysLower = array_map('strtolower', $this->sensitiveBodyKeys);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeysLower, true)) {
                $sanitized[$key] = $this->replacement;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeJsonData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function sanitizeJsonPaths(mixed $data): mixed
    {
        if (!is_array($data) || count($this->sensitiveJsonPaths) === 0) {
            return $data;
        }

        foreach ($this->sensitiveJsonPaths as $rawPath) {
            $cleanPath = (string) $rawPath;
            if (str_starts_with($cleanPath, '$.')) {
                $cleanPath = substr($cleanPath, 2);
            } elseif (str_starts_with($cleanPath, '$')) {
                $cleanPath = substr($cleanPath, 1);
            }

            $segments = explode('.', $cleanPath);
            $data = $this->redactPathSegment($data, $segments);
        }

        return $data;
    }

    /**
     * @param mixed $data
     * @param list<string> $segments
     * @return mixed
     */
    private function redactPathSegment(mixed $data, array $segments): mixed
    {
        if (!is_array($data) || count($segments) === 0) {
            return $data;
        }

        $currentKey = $segments[0];
        $remainingSegments = array_slice($segments, 1);

        if (array_is_list($data)) {
            $result = [];
            foreach ($data as $item) {
                $result[] = $this->redactPathSegment($item, $segments);
            }
            return $result;
        }

        if (array_key_exists($currentKey, $data)) {
            if (count($remainingSegments) === 0) {
                $data[$currentKey] = $this->replacement;
            } else {
                $data[$currentKey] = $this->redactPathSegment($data[$currentKey], $remainingSegments);
            }
        }

        return $data;
    }

    private function sanitizeQueryString(string $query): string
    {
        $pairs = explode('&', $query);
        $sensitiveParamsLower = array_map('strtolower', $this->sensitiveQueryParams);
        $sanitizedPairs = [];

        foreach ($pairs as $pair) {
            if ($pair === '') {
                $sanitizedPairs[] = '';
                continue;
            }

            $parts = explode('=', $pair, 2);
            $keyRaw = $parts[0];
            $valueRaw = $parts[1] ?? null;

            $keyDecoded = rawurldecode($keyRaw);
            if (in_array(strtolower($keyDecoded), $sensitiveParamsLower, true)) {
                if ($valueRaw !== null) {
                    $sanitizedPairs[] = $keyRaw . '=' . rawurlencode($this->replacement);
                } else {
                    $sanitizedPairs[] = $keyRaw;
                }
            } else {
                $sanitizedPairs[] = $pair;
            }
        }

        return implode('&', $sanitizedPairs);
    }

    private function createStream(string $content, \Psr\Http\Message\StreamInterface $originalStream): \Psr\Http\Message\StreamInterface
    {
        if ($this->streamFactory !== null) {
            return $this->streamFactory->createStream($content);
        }

        if (class_exists(\GuzzleHttp\Psr7\Utils::class)) {
            return \GuzzleHttp\Psr7\Utils::streamFor($content);
        }

        // Fallback using php://temp if originalStream class constructor allows or standard stream
        $resource = fopen('php://temp', 'r+');
        if ($resource !== false) {
            fwrite($resource, $content);
            rewind($resource);
            if (class_exists(\GuzzleHttp\Psr7\Stream::class)) {
                return new \GuzzleHttp\Psr7\Stream($resource);
            }
        }

        return $originalStream;
    }
}
