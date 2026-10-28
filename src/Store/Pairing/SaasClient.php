<?php

namespace SaasAgent\AgentStore\Store\Pairing;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\Connection;

/**
 * The store's side of talking to the SaaS.
 *
 * It only ever talks to `agentstore.saas_url`. A pairing link names a pairing id,
 * never a SaaS: an attacker's link cannot point this store at another server.
 */
final class SaasClient
{
    public const API = '/api/agentstore/v1';

    public function __construct(private readonly Http $http) {}

    /** The configured SaaS origin, validated. */
    public function base(): string
    {
        $url = rtrim((string) config('agentstore.saas_url'), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host || ! in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('AGENTSTORE_SAAS_URL is not configured.');
        }

        if ($scheme !== 'https' && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('AGENTSTORE_SAAS_URL must use https.');
        }

        return $url;
    }

    /** Whether a URL the SaaS handed back (a return link) belongs to the configured SaaS. */
    public function owns(string $url): bool
    {
        $base = parse_url($this->base());
        $candidate = parse_url($url);

        return is_array($candidate)
            && ($candidate['scheme'] ?? null) === ($base['scheme'] ?? null)
            && strtolower($candidate['host'] ?? '') === strtolower($base['host'] ?? '')
            && ($candidate['port'] ?? null) === ($base['port'] ?? null)
            && ! isset($candidate['user'])
            && ! isset($candidate['pass']);
    }

    /**
     * @return array{tenant: string, store_url: string, capabilities: list<string>, expires_at: string, return_url: string}
     *
     * @throws RequestException
     */
    public function pairing(string $pairingId): array
    {
        return $this->http->timeout((int) config('agentstore.http.timeout', 10))
            ->acceptJson()
            ->get($this->base().self::API.'/pairings/'.rawurlencode($pairingId))
            ->throw()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function approve(string $pairingId, array $body): array
    {
        return $this->http->timeout((int) config('agentstore.http.timeout', 10))
            ->acceptJson()
            ->post($this->base().self::API.'/pairings/'.rawurlencode($pairingId).'/approve', $body)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Send outbox events, signed with the webhook secret.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<string> the event ids the SaaS accepted
     *
     * @throws RequestException
     */
    public function sendEvents(Connection $connection, array $events): array
    {
        $path = self::API.'/events';
        $body = json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $headers = Signature::headers($connection->key_id, $connection->webhook_secret, 'POST', $this->path($path), [], $body);

        $accepted = $this->http->timeout((int) config('agentstore.http.timeout', 10))
            ->acceptJson()
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->base().$path)
            ->throw()
            ->json('accepted');

        return is_array($accepted) ? array_values(array_filter($accepted, 'is_string')) : [];
    }

    /** The path as the SaaS will see it, including any base path in saas_url. */
    private function path(string $path): string
    {
        return rtrim((string) parse_url($this->base(), PHP_URL_PATH), '/').$path;
    }
}
