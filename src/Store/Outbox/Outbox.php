<?php

namespace SaasAgent\AgentStore\Store\Outbox;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;

/**
 * Events the store sends the SaaS: a stock change, an order shipped.
 *
 * publish() only writes a row. Call it inside the same transaction as the
 * change it describes, and the event exists if and only if the change does —
 * no event for a rolled-back update, no missing event for a committed one.
 * Delivery happens afterwards (`agentstore:deliver`, scheduled every minute),
 * with retries; the SaaS deduplicates on the event id.
 */
final class Outbox
{
    public const TYPES = [
        'product.updated',
        'product.deleted',
        'stock.changed',
        'price.changed',
        'order.status_changed',
        'connection.revoked',
    ];

    private const MAX_PAYLOAD_BYTES = 65536;

    /** @param array<string, mixed> $payload */
    public function publish(string $type, array $payload): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown agentstore event type [{$type}].");
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Agentstore event payloads are limited to 64 KB; send ids, not documents.');
        }

        $event = new OutboxEvent;
        $event->forceFill([
            'event_id' => (string) Str::ulid(),
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => now(),
            'available_at' => now(),
        ])->save();

        return $event->event_id;
    }
}
