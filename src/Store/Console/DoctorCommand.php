<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SaasAgent\AgentStore\AgentStore;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\ContractVersion;
use SaasAgent\AgentStore\Store\Capabilities;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;
use SaasAgent\AgentStore\Store\Pairing\SaasClient;
use Throwable;

/**
 * Checks everything a working integration needs and says what is wrong in
 * plain words. Exits non-zero when a check fails, so it can gate a deploy.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'agentstore:doctor {--offline : Skip the check that the SaaS is reachable}';

    protected $description = 'Diagnose the SaaS Agent store integration';

    private bool $failed = false;

    public function handle(Capabilities $capabilities, SaasClient $saas): int
    {
        $this->components->info('SaaS Agent store integration · contract '.ContractVersion::CURRENT.' · package '.ContractVersion::PACKAGE);

        $storeUrl = AgentStore::storeUrl();
        $this->check(
            filled(config('agentstore.store_url')) ? 'AGENTSTORE_STORE_URL is valid' : 'APP_URL is set',
            $storeUrl !== '' && parse_url($storeUrl, PHP_URL_HOST) !== null,
            $storeUrl,
        );

        try {
            $base = $saas->base();
            $this->check('AGENTSTORE_SAAS_URL is valid', true, $base);
        } catch (Throwable $e) {
            $this->check('AGENTSTORE_SAAS_URL is valid', false, $e->getMessage());
            $base = null;
        }

        $cacheStore = (string) (config('agentstore.pairing_cache_store') ?: config('cache.default'));
        $this->check('Pairing cache is shared between requests', config("cache.stores.{$cacheStore}.driver") !== 'array', $cacheStore);

        $tables = ['agentstore_connections', 'agentstore_idempotency', 'agentstore_outbox'];
        $missing = array_filter($tables, fn ($t) => ! Schema::hasTable($t));
        $this->check('Migrations have run', $missing === [], $missing ? 'missing: '.implode(', ', $missing) : '');

        foreach (Capabilities::INTERFACES as $group => $interface) {
            $class = config("agentstore.adapters.{$group}");
            if (! $class) {
                $this->components->twoColumnDetail("Adapter <fg=gray>{$group}</>", '<fg=gray>not configured</>');

                continue;
            }
            try {
                $capabilities->adapter($group, $interface);
                $this->check("Adapter {$group}", true, class_basename($class));
            } catch (Throwable $e) {
                $this->check("Adapter {$group}", false, $e->getMessage());
            }
        }

        $supported = array_map(fn (Capability $c) => $c->value, rescue(fn () => $capabilities->supported(), [], false));
        $this->components->twoColumnDetail('Capabilities', $supported ? implode(', ', $supported) : '<fg=yellow>none</>');

        $gate = (string) config('agentstore.approval.gate');
        $defined = Gate::has($gate);
        $this->check("Approval gate '{$gate}' is defined", $defined, $defined ? '' : 'nobody can approve a connection until it is');

        $connection = Connection::active();
        $this->components->twoColumnDetail('Connection', $connection
            ? '<fg=green>paired</> · '.($connection->tenant_label ?? '—').' · since '.$connection->connected_at->toDateTimeString()
            : '<fg=yellow>not paired</>');

        $pending = OutboxEvent::query()->whereNull('delivered_at')->whereNull('failed_at')->count();
        $failed = OutboxEvent::query()->whereNotNull('failed_at')->count();
        $this->components->twoColumnDetail('Outbox', "{$pending} pending · {$failed} failed");
        if ($failed > 0) {
            $this->failed = true;
        }

        if ($base && ! $this->option('offline')) {
            try {
                $reachable = Http::timeout(5)->get($base.'/up')->successful();
            } catch (Throwable) {
                $reachable = false;
            }
            $this->check('SaaS is reachable', $reachable, $base);
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $this->components->twoColumnDetail($label, ($ok ? '<fg=green>ok</>' : '<fg=red>fail</>').($detail !== '' ? " <fg=gray>{$detail}</>" : ''));

        $this->failed = $this->failed || ! $ok;
    }
}
