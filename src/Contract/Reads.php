<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Typed reads from an untrusted array, for the DTOs' fromArray().
 *
 * Every getter throws ContractException naming the offending path, so a bad
 * store response is reported precisely instead of surfacing later as a null.
 *
 * @internal
 */
trait Reads
{
    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key, string $path, int $max = 1000): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw ContractException::field("{$path}.{$key}", 'a string');
        }

        $value = (string) $value;

        if ($value === '' || mb_strlen($value) > $max) {
            throw ContractException::field("{$path}.{$key}", "a non-empty string of at most {$max} characters");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optStr(array $data, string $key, string $path, int $max = 1000): ?string
    {
        if (! isset($data[$key]) || $data[$key] === '') {
            return null;
        }

        return self::str($data, $key, $path, $max);
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < $min || $value > $max) {
            throw ContractException::field("{$path}.{$key}", "an integer between {$min} and {$max}");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optInt(array $data, string $key, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        return array_key_exists($key, $data) && $data[$key] !== null ? self::int($data, $key, $path, $min, $max) : null;
    }

    /** @param array<string, mixed> $data */
    private static function bool(array $data, string $key, string $path): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw ContractException::field("{$path}.{$key}", 'a boolean');
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arr(array $data, string $key, string $path, bool $optional = false): array
    {
        if ($optional && ! isset($data[$key])) {
            return [];
        }

        if (! is_array($data[$key] ?? null)) {
            throw ContractException::field("{$path}.{$key}", 'an array');
        }

        return $data[$key];
    }

    /**
     * A list of strings, each bounded.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key, string $path, int $max = 1000, int $count = 100): array
    {
        $values = self::arr($data, $key, $path, optional: true);

        if (count($values) > $count) {
            throw ContractException::field("{$path}.{$key}", "a list of at most {$count} items");
        }

        return array_values(array_map(function ($value, $index) use ($key, $path, $max) {
            if (! is_string($value) || mb_strlen($value) > $max) {
                throw ContractException::field("{$path}.{$key}.{$index}", "a string of at most {$max} characters");
            }

            return $value;
        }, $values, array_keys($values)));
    }

    /**
     * A flat map of short strings — attributes, options. Nothing nested, nothing huge.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function stringMap(array $data, string $key, string $path, int $count = 50): array
    {
        $values = self::arr($data, $key, $path, optional: true);

        if (count($values) > $count) {
            throw ContractException::field("{$path}.{$key}", "a map of at most {$count} entries");
        }

        $map = [];
        foreach ($values as $name => $value) {
            if (! is_string($name) || mb_strlen($name) > 100 || ! is_scalar($value) || mb_strlen((string) $value) > 500) {
                throw ContractException::field("{$path}.{$key}", 'a map of short strings');
            }
            $map[$name] = (string) $value;
        }

        return $map;
    }

    /** @param array<string, mixed> $data */
    private static function optTime(array $data, string $key, string $path): ?\DateTimeImmutable
    {
        if (! isset($data[$key])) {
            return null;
        }

        $value = $data[$key];
        $time = is_string($value) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value) : false;

        if ($time === false) {
            throw ContractException::field("{$path}.{$key}", 'an ISO-8601 timestamp');
        }

        return $time;
    }

    private static function time(?\DateTimeInterface $time): ?string
    {
        return $time?->format(\DateTimeInterface::ATOM);
    }
}
