<?php

namespace SaasAgent\AgentStore\Security;

/**
 * HMAC-SHA256 request signatures, the same in both directions. See ADR 0010.
 *
 * Canonical string, newline separated:
 *   v1, METHOD, path, sorted query, timestamp, nonce, sha256(body)
 *
 * The host is not signed (proxies rewrite it); the path and query are, so a
 * signed request for one resource cannot be replayed against another.
 */
final class Signature
{
    public const VERSION = 'v1';

    public const KEY_HEADER = 'X-Agentstore-Key';

    public const TIMESTAMP_HEADER = 'X-Agentstore-Timestamp';

    public const NONCE_HEADER = 'X-Agentstore-Nonce';

    public const SIGNATURE_HEADER = 'X-Agentstore-Signature';

    /**
     * Headers for an outgoing request.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function headers(
        string $keyId,
        string $secret,
        string $method,
        string $path,
        array $query,
        string $body,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));

        return [
            self::KEY_HEADER => $keyId,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::NONCE_HEADER => $nonce,
            self::SIGNATURE_HEADER => self::VERSION.'='.self::compute(
                $secret,
                self::canonical($method, $path, $query, $timestamp, $nonce, $body),
            ),
        ];
    }

    /** @param array<string, mixed> $query */
    public static function canonical(string $method, string $path, array $query, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            self::VERSION,
            strtoupper($method),
            '/'.ltrim($path, '/'),
            self::query($query),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function compute(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /** Constant-time check of a `v1=<hex>` header against one secret. */
    public static function matches(string $secret, string $canonical, string $header): bool
    {
        if (! str_starts_with($header, self::VERSION.'=')) {
            return false;
        }

        return hash_equals(self::compute($secret, $canonical), substr($header, strlen(self::VERSION) + 1));
    }

    /** Sorted, RFC 3986 encoded — the same bytes whatever order the sender used. */
    public static function query(array $query): string
    {
        $sort = function (array &$values) use (&$sort): void {
            ksort($values);
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $sort($value);
                }
            }
        };
        $sort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function validNonce(string $nonce): bool
    {
        return (bool) preg_match('/\A[a-f0-9]{32}\z/', $nonce);
    }

    /**
     * Proof that the holder of `secret` saw a value — used once, at the end of
     * pairing and rotation, so a store shows it stored the new secret.
     */
    public static function proof(string $secret, string ...$parts): string
    {
        return hash_hmac('sha256', 'proof'."\n".implode("\n", $parts), $secret);
    }
}
