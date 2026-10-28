<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\ContractVersion;
use SaasAgent\AgentStore\Store\Capabilities;
use Throwable;

/**
 * Health and capability discovery. Both are signed: they tell the SaaS what
 * this store runs, which is nobody else's business. Neither reports anything
 * about the store's infrastructure beyond "up" or "degraded".
 */
final class StatusController extends ApiController
{
    public function health(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return new JsonResponse([
            'data' => [
                'status' => $database === 'ok' ? 'ok' : 'degraded',
                'checks' => ['database' => $database],
                'contract_version' => ContractVersion::CURRENT,
                'time' => now()->toAtomString(),
            ],
        ], $database === 'ok' ? 200 : 503);
    }

    public function capabilities(Capabilities $capabilities): JsonResponse
    {
        return $this->respond(fn () => [
            'contract_version' => ContractVersion::CURRENT,
            'supported_versions' => ContractVersion::SUPPORTED,
            'package_version' => ContractVersion::PACKAGE,
            // Major.minor only: enough for compatibility, too little to fingerprint patches.
            'laravel_version' => implode('.', array_slice(explode('.', app()->version()), 0, 2)),
            'capabilities' => array_map(fn (Capability $c) => $c->value, $capabilities->supported()),
            'store' => [
                'name' => (string) config('app.name'),
                'url' => (string) config('app.url'),
                'timezone' => (string) config('app.timezone'),
            ],
        ]);
    }
}
