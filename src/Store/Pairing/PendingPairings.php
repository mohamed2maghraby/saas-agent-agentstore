<?php

namespace SaasAgent\AgentStore\Store\Pairing;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Approvals waiting for the SaaS, in the store's cache until they expire or
 * are used (`agentstore.pairing_cache_store`, the default store when empty).
 * The cache must be shared by the store's web requests (file, database,
 * redis…), not `array`. Losing it — `cache:clear` mid-pairing — only means
 * approving again.
 */
final class PendingPairings
{
    private const PREFIX = 'agentstore:pairing:';

    public function put(Pairing $pairing): void
    {
        $this->cache()->put(self::PREFIX.$pairing->pairingId, $pairing->toArray(), $pairing->expiresAt);
    }

    public function find(string $pairingId): ?Pairing
    {
        $data = $this->cache()->get(self::PREFIX.$pairingId);

        return is_array($data) ? Pairing::fromArray($data) : null;
    }

    public function forget(string $pairingId): void
    {
        $this->cache()->forget(self::PREFIX.$pairingId);
    }

    /**
     * Runs $then with the pairing held, so two completions of one pairing
     * cannot both pass.
     *
     * @template T
     *
     * @param  Closure(?Pairing): T  $then
     * @return T
     */
    public function using(string $pairingId, Closure $then): mixed
    {
        return $this->cache()->lock(self::PREFIX.'lock:'.$pairingId, 30)
            ->block(10, fn () => $then($this->find($pairingId)));
    }

    private function cache(): Repository
    {
        return Cache::store(config('agentstore.pairing_cache_store') ?: null);
    }
}
