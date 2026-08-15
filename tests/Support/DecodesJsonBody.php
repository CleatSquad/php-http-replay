<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Support;

/**
 * Decodes JSON bodies in assertions, failing the test rather than the type checker.
 */
trait DecodesJsonBody
{
    /**
     * @return array<array-key, mixed>
     */
    protected static function decodeJsonObject(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            self::fail('Expected the body to decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    protected static function nestedObject(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            self::fail(sprintf('Expected key "%s" to hold a JSON object.', $key));
        }

        return $value;
    }
}
