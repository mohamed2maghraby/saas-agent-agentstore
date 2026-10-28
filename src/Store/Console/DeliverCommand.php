<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;
use SaasAgent\AgentStore\Store\Outbox\OutboxDelivery;

/** Sends pending outbox events to the SaaS. Scheduled every minute. */
final class DeliverCommand extends Command
{
    protected $signature = 'agentstore:deliver {--batches=10 : Batches to send this run}';

    protected $description = 'Deliver pending SaaS Agent events';

    public function handle(OutboxDelivery $delivery): int
    {
        $delivered = 0;
        $failed = 0;

        for ($i = 0; $i < max(1, (int) $this->option('batches')); $i++) {
            $result = $delivery->deliver();
            $delivered += $result['delivered'];
            $failed += $result['failed'];

            if ($result['skipped'] || $result['delivered'] === 0) {
                break;
            }
        }

        $this->components->info("Delivered {$delivered} event(s), {$failed} will be retried.");

        return self::SUCCESS;
    }
}
