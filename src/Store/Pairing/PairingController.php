<?php

namespace SaasAgent\AgentStore\Store\Pairing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use SaasAgent\AgentStore\AgentStore;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\ContractVersion;
use SaasAgent\AgentStore\Store\Capabilities;
use Throwable;

/**
 * The store admin's side of pairing (ADR 0009): look at a pairing request and
 * approve it. Runs behind the store's own login (`agentstore.approval.middleware`)
 * and the `agentstore.approval.gate` — which, until the store defines it, says no.
 *
 * Nothing in the query string is trusted beyond the pairing id: the details are
 * fetched from the configured SaaS, and fetched again on approval.
 */
final class PairingController
{
    public function __construct(
        private readonly SaasClient $saas,
        private readonly Capabilities $capabilities,
        private readonly PendingPairings $pending,
    ) {}

    public function show(Request $request): View
    {
        $this->authorizeApprover();

        return view('agentstore::connect', $this->details($this->pairingId($request)));
    }

    public function approve(Request $request): RedirectResponse|View
    {
        $this->authorizeApprover();

        $pairingId = $this->pairingId($request);
        $details = $this->details($pairingId);

        if ($details['error'] ?? null) {
            return view('agentstore::connect', $details);
        }

        $token = Str::random(64);
        $capabilities = $details['capabilities'];

        $this->pending->put(new Pairing(
            $pairingId,
            hash('sha256', $token),
            $capabilities,
            $details['tenant'],
            $request->user()?->getAuthIdentifier(),
            CarbonImmutable::instance($details['expires']),
        ));

        try {
            $this->saas->approve($pairingId, [
                'approval_token' => $token,
                'capabilities' => $capabilities,
                'store' => [
                    'name' => (string) config('app.name'),
                    'url' => AgentStore::storeUrl(),
                    'contract_version' => ContractVersion::CURRENT,
                    'package_version' => ContractVersion::PACKAGE,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->pending->forget($pairingId);

            return view('agentstore::connect', ['error' => __('agentstore::messages.approve_failed')]);
        }

        // Back to the SaaS — but only to the SaaS this store is configured for.
        return $this->saas->owns($details['return_url'])
            ? redirect()->away($details['return_url'])
            : redirect()->to('/')->with('status', __('agentstore::messages.approved'));
    }

    private function authorizeApprover(): void
    {
        $gate = (string) config('agentstore.approval.gate', 'agentstore-approve');

        // Undefined gate means "no": the store has not decided who may approve.
        abort_unless(Gate::has($gate) && Gate::allows($gate), 403);
    }

    private function pairingId(Request $request): string
    {
        $id = (string) $request->input('pairing', '');

        abort_unless((bool) preg_match('/\A[0-9a-z]{26}\z/', $id), 404);

        return $id;
    }

    /** @return array<string, mixed> */
    private function details(string $pairingId): array
    {
        try {
            $data = $this->saas->pairing($pairingId);
        } catch (RequestException $e) {
            return ['error' => __($e->response->status() === 404 ? 'agentstore::messages.not_found' : 'agentstore::messages.saas_unreachable')];
        } catch (Throwable $e) {
            report($e);

            return ['error' => __('agentstore::messages.saas_unreachable')];
        }

        // In this app's timezone: the SaaS may run in another zone.
        $expires = rescue(fn () => Carbon::parse((string) ($data['expires_at'] ?? ''))->setTimezone(config('app.timezone')), null, false);

        if (! $expires || $expires->isPast()) {
            return ['error' => __('agentstore::messages.expired')];
        }

        // A pairing created for another store must not be approved here.
        if (! $this->sameOrigin((string) ($data['store_url'] ?? ''), AgentStore::storeUrl())) {
            return ['error' => __('agentstore::messages.wrong_store')];
        }

        $requested = array_values(array_intersect(
            array_map('strval', (array) ($data['capabilities'] ?? [])),
            Capability::values(),
        ));
        $supported = array_map(fn (Capability $c) => $c->value, $this->capabilities->supported());

        return [
            'pairingId' => $pairingId,
            'tenant' => mb_substr((string) ($data['tenant'] ?? ''), 0, 120),
            'saasHost' => (string) parse_url($this->saas->base(), PHP_URL_HOST),
            // Only what was asked for and this store can actually do.
            'capabilities' => array_values(array_intersect($requested, $supported)),
            'expires' => $expires,
            'return_url' => (string) ($data['return_url'] ?? ''),
        ];
    }

    private function sameOrigin(string $a, string $b): bool
    {
        $normalize = function (string $url): ?string {
            $parts = parse_url($url);
            if (! is_array($parts) || empty($parts['host'])) {
                return null;
            }
            $scheme = strtolower($parts['scheme'] ?? 'https');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            return $scheme.'://'.strtolower($parts['host']).':'.$port;
        };

        return $normalize($a) !== null && $normalize($a) === $normalize($b);
    }
}
