<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Internal;

/**
 * Narrows values coming out of JSON or YAML decoding to the shapes cassettes expect.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class DecodedValue
{
    /**
     * Reads a decoded value as a string, falling back when it is not scalar.
     */
    public static function asString(mixed $value, string $default = ''): string
    {
        if ($value === null || is_array($value) || is_object($value) || is_resource($value)) {
            return $default;
        }

        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '',
            is_int($value), is_float($value) => (string) $value,
            default => $default,
        };
    }

    /**
     * Reads a decoded value as an integer, falling back when it is not numeric.
     */
    public static function asInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Reads a decoded value as a map keyed by strings, dropping anything else.
     *
     * @return array<string, mixed>
     */
    public static function asStringKeyedMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * Normalizes a decoded header map into the PSR-7 header shape.
     *
     * @param  mixed $value
     * @return array<string, string|list<string>>
     */
    public static function asHeaders(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $headers = [];
        foreach ($value as $name => $values) {
            $header = self::asString($name);
            if ($header === '') {
                continue;
            }

            $headers[$header] = is_array($values)
                ? array_values(array_map(static fn (mixed $item): string => self::asString($item), $values))
                : self::asString($values);
        }

        return $headers;
    }
}
