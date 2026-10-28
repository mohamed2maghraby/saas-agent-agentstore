<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;
use SaasAgent\AgentStore\Store\Models\IdempotencyRecord;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;
use SaasAgent\AgentStore\Store\Models\Pairing;
use SaasAgent\AgentStore\Store\Models\RequestRecord;

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
        $requests = RequestRecord::query()->where('created_at', '<', now()->subDays((int) config('agentstore.request_log_retention_days', 30)))->delete();
        $keys = IdempotencyRecord::query()
            ->where('status', IdempotencyRecord::COMPLETED)
            ->where('created_at', '<', now()->subDays((int) config('agentstore.idempotency.retention_days', 30)))
            ->delete();
        $events = OutboxEvent::query()
            ->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhereNotNull('failed_at'))
            ->where('occurred_at', '<', now()->subDays((int) config('agentstore.outbox.retention_days', 14)))
            ->delete();
        $pairings = Pairing::query()->where('expires_at', '<', now()->subDay())->delete();

        $this->components->info("Pruned {$requests} request(s), {$keys} idempotency record(s), {$events} event(s), {$pairings} pairing(s).");

        return self::SUCCESS;
    }
}
