<?php

namespace SaasAgent\AgentStore;

use SaasAgent\AgentStore\Store\Outbox\Outbox;

/**
 * The one call a store's own code makes into the package: tell the SaaS that
 * something changed.
 *
 *     DB::transaction(function () use ($order) {
 *         $order->update(['status' => 'shipped']);
 *         AgentStore::publish('order.status_changed', ['order_id' => (string) $order->id]);
 *     });
 *
 * Send ids and states, not whole documents; the SaaS reads current data back
 * through the adapters when it needs it.
 */
final class AgentStore
{
    /** @param array<string, mixed> $payload */
    public static function publish(string $type, array $payload): string
    {
        return app(Outbox::class)->publish($type, $payload);
    }

    /**
     * The address the SaaS knows this store by: `agentstore.store_url`
     * (AGENTSTORE_STORE_URL) when set, otherwise APP_URL.
     */
    public static function storeUrl(): string
    {
        $configured = config('agentstore.store_url');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : (string) config('app.url'), '/');
    }
}
