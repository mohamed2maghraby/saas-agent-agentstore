<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;
use SaasAgent\AgentStore\Store\Models\IdempotencyRecord;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;

/**
 * Deletes package records past their retention. Undelivered outbox events are
 * kept however old they are — they have not done their job yet.
 */
final class PruneCommand extends Command
{
    protected $signature = 'agentstore:prune';

    protected $description = 'Prune old SaaS Agent integration records';

    public function handle(): int
    {
        $keys = IdempotencyRecord::query()
            ->where('status', IdempotencyRecord::COMPLETED)
            ->where('created_at', '<', now()->subDays((int) config('agentstore.idempotency.retention_days', 30)))
            ->delete();
        $events = OutboxEvent::query()
            ->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhereNotNull('failed_at'))
            ->where('occurred_at', '<', now()->subDays((int) config('agentstore.outbox.retention_days', 14)))
            ->delete();

        $this->components->info("Pruned {$keys} idempotency record(s), {$events} event(s).");

        return self::SUCCESS;
    }
}
