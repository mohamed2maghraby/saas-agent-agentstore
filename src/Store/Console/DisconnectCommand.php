<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Store\Outbox\Outbox;
use SaasAgent\AgentStore\Store\Outbox\OutboxDelivery;
use Throwable;

/**
 * Ends the pairing from the store's side. The credentials are deleted first, so
 * the SaaS is locked out even if telling it fails; it is told on a best-effort
 * basis so its dashboard shows the store as disconnected.
 */
final class DisconnectCommand extends Command
{
    protected $signature = 'agentstore:disconnect {--force : Do not ask for confirmation}';

    protected $description = 'Disconnect this store from SaaS Agent and delete its credentials';

    public function handle(Outbox $outbox, OutboxDelivery $delivery): int
    {
        $connection = Connection::active();

        if (! $connection) {
            $this->components->info('This store is not connected.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Disconnect from SaaS Agent? The AI will stop answering for this store.')) {
            return self::FAILURE;
        }

        // Tell the SaaS while the webhook secret still exists, then delete it.
        try {
            $outbox->publish('connection.revoked', ['key_id' => $connection->key_id]);
            $delivery->deliver();
        } catch (Throwable $e) {
            report($e);
        }

        Connection::query()->delete();

        $this->components->info('Disconnected. The credentials have been deleted.');

        return self::SUCCESS;
    }
}
