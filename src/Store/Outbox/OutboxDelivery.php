<?php

namespace SaasAgent\AgentStore\Store\Outbox;

use Illuminate\Support\Facades\DB;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;
use SaasAgent\AgentStore\Store\Pairing\SaasClient;
use Throwable;

/**
 * Sends pending outbox events to the SaaS in batches, oldest first.
 *
 * Delivery is at-least-once: a batch whose response is lost is sent again, and
 * the SaaS drops the duplicates by event id. A failing batch backs off
 * exponentially (1, 2, 4 … 60 minutes) and gives up after `max_attempts`,
 * leaving the rows marked failed for `agentstore:doctor` to report.
 */
final class OutboxDelivery
{
    public function __construct(private readonly SaasClient $saas) {}

    /** @return array{delivered: int, failed: int, skipped: bool} */
    public function deliver(): array
    {
        $connection = Connection::active();

        if (! $connection) {
            return ['delivered' => 0, 'failed' => 0, 'skipped' => true];
        }

        $events = OutboxEvent::query()
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, (int) config('agentstore.outbox.batch_size', 50)))
            ->get();

        if ($events->isEmpty()) {
            return ['delivered' => 0, 'failed' => 0, 'skipped' => false];
        }

        try {
            $accepted = $this->saas->sendEvents($connection, $events->map(fn (OutboxEvent $e) => [
                'id' => $e->event_id,
                'type' => $e->type,
                'occurred_at' => $e->occurred_at->toAtomString(),
                'payload' => $e->payload,
            ])->all());
        } catch (Throwable $e) {
            $this->backOff($events, $e->getMessage());

            return ['delivered' => 0, 'failed' => $events->count(), 'skipped' => false];
        }

        // The SaaS lists what it took (including duplicates it already had).
        // Anything it did not list is retried.
        $delivered = $events->whereIn('event_id', $accepted);
        $rejected = $events->whereNotIn('event_id', $accepted);

        DB::transaction(function () use ($delivered): void {
            OutboxEvent::query()->whereKey($delivered->modelKeys())->update(['delivered_at' => now()]);
        });

        if ($rejected->isNotEmpty()) {
            $this->backOff($rejected, 'Not accepted by the SaaS.');
        }

        return ['delivered' => $delivered->count(), 'failed' => $rejected->count(), 'skipped' => false];
    }

    private function backOff($events, string $error): void
    {
        $max = (int) config('agentstore.outbox.max_attempts', 20);

        foreach ($events as $event) {
            $attempts = $event->attempts + 1;

            $event->forceFill([
                'attempts' => $attempts,
                'available_at' => now()->addMinutes(min(60, 2 ** ($attempts - 1))),
                'last_error' => mb_substr($error, 0, 500),
                'failed_at' => $attempts >= $max ? now() : null,
            ])->save();
        }
    }
}
