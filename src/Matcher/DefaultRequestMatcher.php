<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Matcher;

use CleatSquad\HttpReplay\Contract\RequestMatcherInterface;
use CleatSquad\HttpReplay\Model\Difference;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Model\MatchResult;
use Psr\Http\Message\RequestInterface;

final class DefaultRequestMatcher implements RequestMatcherInterface
{
    /**
     * Header names ignored by default. Lowercase for fast comparison.
     */
    private const DEFAULT_IGNORED_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'user-agent',
        'date',
        'x-request-id',
        'traceparent',
        'content-length',
    ];

    /**
     * @param list<string> $ignoredHeaders Custom headers to ignore in addition to or replacing defaults.
     */
    public function __construct(
        private readonly array $ignoredHeaders = self::DEFAULT_IGNORED_HEADERS,
    ) {
    }

    public function match(RequestInterface $request, Exchange $recorded): MatchResult
    {
        $recordedRequest = $recorded->request();
        $differences = [];

        // 1. Method
        if ($request->getMethod() !== $recordedRequest->getMethod()) {
            $diff = Difference::method($recordedRequest->getMethod(), $request->getMethod());
            $differences['method'] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
        }

        // 2. URI
        $reqUri = $request->getUri();
        $recUri = $recordedRequest->getUri();

        if (
            $reqUri->getScheme() !== $recUri->getScheme()
            || $reqUri->getHost() !== $recUri->getHost()
            || $reqUri->getPort() !== $recUri->getPort()
            || $reqUri->getPath() !== $recUri->getPath()
        ) {
            $diff = Difference::uri((string) $recUri, (string) $reqUri);
            $differences['uri'] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
        } else {
            // Compare Query parameters semantically
            $reqQuery = $this->parseQuery($reqUri->getQuery());
            $recQuery = $this->parseQuery($recUri->getQuery());

            if ($reqQuery !== $recQuery) {
                $diff = Difference::uri((string) $recUri, (string) $reqUri);
                $differences['uri.query'] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
            }
        }

        // 3. Headers
        $reqHeaders = $this->normalizeHeaders($request->getHeaders());
        $recHeaders = $this->normalizeHeaders($recordedRequest->getHeaders());

        $allHeaderNames = array_unique(array_merge(array_keys($reqHeaders), array_keys($recHeaders)));

        $ignored = array_map('strtolower', $this->ignoredHeaders);

        foreach ($allHeaderNames as $name) {
            if (in_array($name, $ignored, true)) {
                continue;
            }

            $reqVals = $reqHeaders[$name] ?? null;
            $recVals = $recHeaders[$name] ?? null;

            if ($reqVals !== $recVals) {
                $expectedStr = $recVals !== null ? implode(', ', $recVals) : '<missing>';
                $actualStr = $reqVals !== null ? implode(', ', $reqVals) : '<missing>';

                $diff = Difference::header($name, $expectedStr, $actualStr);
                $differences['header.' . $name] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
            }
        }

        // 4. Body
        $reqBodyStr = (string) $request->getBody();
        $recBodyStr = (string) $recordedRequest->getBody();

        // Rewind streams if seekable
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }
        if ($recordedRequest->getBody()->isSeekable()) {
            $recordedRequest->getBody()->rewind();
        }

        $reqJsonValid = $this->isValidJson($reqBodyStr);
        $recJsonValid = $this->isValidJson($recBodyStr);

        if ($reqJsonValid && $recJsonValid) {
            $reqJson = json_decode($reqBodyStr, true);
            $recJson = json_decode($recBodyStr, true);

            $jsonDiffs = [];
            $this->compareJsonValues($recJson, $reqJson, 'body', $jsonDiffs);

            foreach ($jsonDiffs as $path => $diff) {
                $differences[$path] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
            }
        } else {
            // Deterministic string/byte comparison
            if ($reqBodyStr !== $recBodyStr) {
                $diff = Difference::body($recBodyStr, $reqBodyStr);
                $differences['body'] = $diff->describe() . sprintf(' (expected: %s, actual: %s)', $diff->expected, $diff->actual);
            }
        }

        if (count($differences) > 0) {
            return MatchResult::mismatch($differences);
        }

        return MatchResult::success();
    }

    /**
     * Parses query string into structured parameters without altering duplicate query parameter semantics.
     * e.g., "a=1&b=2" -> ['a' => ['1'], 'b' => ['2']]
     * "a=1&a=2" -> ['a' => ['1', '2']]
     *
     * @return array<string, list<string>>
     */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $pairs = explode('&', $query);
        $parsed = [];

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';

            $parsed[$key][] = $value;
        }

        ksort($parsed);

        return $parsed;
    }

    /**
     * Normalize headers array to lowercase keys.
     *
     * @param  array<array-key, array<array-key, string>> $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = array_values($values);
        }
        return $normalized;
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

    /**
     * Recursively compares decoded JSON data structures.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $path
     * @param array<string, Difference> $diffs Output differences accumulator
     */
    private function compareJsonValues(mixed $expected, mixed $actual, string $path, array &$diffs): void
    {
        // 1. Strict scalar/type comparison if either is not an array
        if (!is_array($expected) || !is_array($actual)) {
            if ($expected !== $actual) {
                $expStr = json_encode($expected);
                $actStr = json_encode($actual);
                $diffs[$path] = Difference::bodyAt($path, $expStr !== false ? $expStr : '', $actStr !== false ? $actStr : '');
            }
            return;
        }

        // 2. Check if array is list (sequential indexed array) or associative object
        $expectedIsList = array_is_list($expected);
        $actualIsList = array_is_list($actual);

        if ($expectedIsList !== $actualIsList) {
            $diffs[$path] = Difference::bodyAt(
                $path,
                $expectedIsList ? 'array' : 'object',
                $actualIsList ? 'array' : 'object'
            );
            return;
        }

        if ($expectedIsList) {
            // Preserve array ordering
            $maxCount = max(count($expected), count($actual));
            for ($i = 0; $i < $maxCount; $i++) {
                $itemPath = $path . '.' . $i;
                if (!array_key_exists($i, $expected)) {
                    $diffs[$itemPath] = Difference::bodyAt($itemPath, '<missing>', json_encode($actual[$i]) ?: '');
                } elseif (!array_key_exists($i, $actual)) {
                    $diffs[$itemPath] = Difference::bodyAt($itemPath, json_encode($expected[$i]) ?: '', '<missing>');
                } else {
                    $this->compareJsonValues($expected[$i], $actual[$i], $itemPath, $diffs);
                }
            }
        } else {
            // Compare objects independently of key order
            $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            sort($allKeys);

            foreach ($allKeys as $key) {
                $keyStr = (string) $key;
                $itemPath = $path . '.' . $keyStr;

                if (!array_key_exists($keyStr, $expected)) {
                    $diffs[$itemPath] = Difference::bodyAt($itemPath, '<missing>', json_encode($actual[$keyStr]) ?: '');
                } elseif (!array_key_exists($keyStr, $actual)) {
                    $diffs[$itemPath] = Difference::bodyAt($itemPath, json_encode($expected[$keyStr]) ?: '', '<missing>');
                } else {
                    $this->compareJsonValues($expected[$keyStr], $actual[$keyStr], $itemPath, $diffs);
                }
            }
        }
    }
}
