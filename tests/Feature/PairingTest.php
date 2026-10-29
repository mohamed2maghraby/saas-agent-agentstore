<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Store\Models\Pairing;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\CatalogAdapter;
use SaasAgent\AgentStore\Tests\TestCase;

/** The store side of ADR 0009. The SaaS is faked; the store is real. */
class PairingTest extends TestCase
{
    private const PAIRING = '01jcx0000000000000000000aa';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A stand-in for the store's own login: the test acts as a user.
        $app['config']->set('agentstore.approval.middleware', ['web']);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', fn () => 'store home');
    }

    private function fakeSaas(array $overrides = [], int $approveStatus = 202): void
    {
        Http::fake([
            'https://saas.test/api/agentstore/v1/pairings/'.self::PAIRING => Http::response(['data' => array_merge([
                'tenant' => 'Space Gift Shop',
                'store_url' => 'https://shop.test',
                'capabilities' => ['products.search', 'orders.create'],
                'expires_at' => now()->addMinutes(10)->toAtomString(),
                'return_url' => 'https://saas.test/t/abc/integrations/1',
            ], $overrides)]),
            'https://saas.test/api/agentstore/v1/pairings/'.self::PAIRING.'/approve' => Http::response(['data' => ['status' => 'approved']], $approveStatus),
        ]);
    }

    private function asApprover(): self
    {
        Gate::define('agentstore-approve', fn () => true);

        return $this->actingAs((new User)->forceFill(['id' => 7]));
    }

    public function test_nobody_can_approve_until_the_store_defines_who_may(): void
    {
        $this->fakeSaas();

        $this->actingAs((new User)->forceFill(['id' => 7]))->get('/agentstore/connect?pairing='.self::PAIRING)->assertForbidden();

        Gate::define('agentstore-approve', fn () => false);
        $this->actingAs((new User)->forceFill(['id' => 7]))->get('/agentstore/connect?pairing='.self::PAIRING)->assertForbidden();
    }

    public function test_the_approval_page_shows_what_the_configured_saas_reports(): void
    {
        config(['agentstore.adapters.products' => CatalogAdapter::class]);
        $this->fakeSaas();

        $this->asApprover()->get('/agentstore/connect?pairing='.self::PAIRING)
            ->assertOk()
            ->assertSee('Space Gift Shop')
            ->assertSee('saas.test')
            // Capability names contain a dot; the label must still be found.
            ->assertSee(__('agentstore::messages.capabilities')['products.search'])
            ->assertDontSee('agentstore::messages');

        // Fetched from the configured SaaS — nothing else.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://saas.test/'));
    }

    public function test_a_pairing_created_for_another_store_is_refused(): void
    {
        $this->fakeSaas(['store_url' => 'https://another-shop.test']);

        $this->asApprover()->get('/agentstore/connect?pairing='.self::PAIRING)->assertOk()->assertSee(__('agentstore::messages.wrong_store'));
        $this->asApprover()->post('/agentstore/connect', ['pairing' => self::PAIRING]);

        $this->assertSame(0, Pairing::query()->count());
    }

    public function test_the_store_can_be_known_by_an_address_other_than_app_url(): void
    {
        // A local copy whose APP_URL still names production.
        config(['app.url' => 'https://production-shop.example', 'agentstore.store_url' => 'https://shop.test/']);
        $this->fakeSaas();

        $this->asApprover()->get('/agentstore/connect?pairing='.self::PAIRING)
            ->assertOk()
            ->assertDontSee(__('agentstore::messages.wrong_store'));

        // And the other way round: with the override set, APP_URL no longer matches.
        config(['agentstore.store_url' => 'https://elsewhere.test']);
        $this->asApprover()->get('/agentstore/connect?pairing='.self::PAIRING)
            ->assertSee(__('agentstore::messages.wrong_store'));
    }

    public function test_a_saas_in_another_timezone_does_not_expire_the_approval_early(): void
    {
        // The store runs on Cairo time; the SaaS sends its expiry in UTC.
        $zone = date_default_timezone_get();
        date_default_timezone_set('Africa/Cairo');
        config(['app.timezone' => 'Africa/Cairo']);

        try {
            $this->fakeSaas(['expires_at' => now('UTC')->addMinutes(10)->toAtomString()]);
            $this->asApprover()->post('/agentstore/connect', ['pairing' => self::PAIRING]);

            $pairing = Pairing::query()->sole();
            $this->assertTrue($pairing->isOpen(), 'The approval expired before it could be completed.');
            $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $pairing->expires_at->getTimestamp(), 60);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_an_expired_pairing_is_refused(): void
    {
        $this->fakeSaas(['expires_at' => now()->subMinute()->toAtomString()]);

        $this->asApprover()->get('/agentstore/connect?pairing='.self::PAIRING)->assertSee(__('agentstore::messages.expired'));
    }

    public function test_a_malformed_pairing_id_is_not_found(): void
    {
        $this->asApprover()->get('/agentstore/connect?pairing=../../etc')->assertNotFound();
    }

    public function test_approving_sends_a_token_to_the_saas_and_keeps_only_its_hash(): void
    {
        $this->fakeSaas();

        $this->asApprover()->post('/agentstore/connect', ['pairing' => self::PAIRING])
            ->assertRedirect('https://saas.test/t/abc/integrations/1');

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            if (str_ends_with($request->url(), '/approve')) {
                $sent = $request->data();

                return true;
            }

            return false;
        });

        $pairing = Pairing::query()->sole();
        $this->assertSame(hash('sha256', $sent['approval_token']), $pairing->token_hash);
        $this->assertNotSame($sent['approval_token'], $pairing->token_hash);
        $this->assertSame(7, (int) $pairing->approved_by);
        // Only capabilities that were both requested and supported (none configured here).
        $this->assertSame([], $pairing->capabilities);
    }

    public function test_a_return_url_outside_the_configured_saas_is_not_followed(): void
    {
        $this->fakeSaas(['return_url' => 'https://evil.test/phish']);

        $this->asApprover()->post('/agentstore/connect', ['pairing' => self::PAIRING])->assertRedirect('/');
    }

    public function test_the_saas_completes_pairing_with_the_right_token_only_once(): void
    {
        [$token, $credentials] = $this->pendingApproval();

        $body = ['pairing_id' => self::PAIRING, 'approval_token' => $token, 'key_id' => $credentials->keyId, 'request_secret' => $credentials->requestSecret, 'webhook_secret' => $credentials->webhookSecret];

        $response = $this->postJson('/agentstore/v1/pairing/verify', $body)->assertOk();
        $this->assertSame(Signature::proof($credentials->requestSecret, self::PAIRING, $credentials->keyId), $response->json('data.proof'));

        $connection = Connection::active();
        $this->assertSame($credentials->keyId, $connection->key_id);
        // Encrypted at rest.
        $this->assertStringNotContainsString($credentials->requestSecret, (string) $connection->getRawOriginal('request_secret'));

        // Single use.
        $this->postJson('/agentstore/v1/pairing/verify', $body)->assertForbidden();
    }

    public function test_a_wrong_token_does_not_pair_and_does_not_burn_the_approval(): void
    {
        [$token, $credentials] = $this->pendingApproval();
        $body = ['pairing_id' => self::PAIRING, 'key_id' => $credentials->keyId, 'request_secret' => $credentials->requestSecret, 'webhook_secret' => $credentials->webhookSecret];

        $this->postJson('/agentstore/v1/pairing/verify', $body + ['approval_token' => 'guess'])->assertForbidden();
        $this->assertNull(Connection::active());

        $this->postJson('/agentstore/v1/pairing/verify', $body + ['approval_token' => $token])->assertOk();
    }

    public function test_an_expired_approval_cannot_be_completed(): void
    {
        [$token, $credentials] = $this->pendingApproval();
        Pairing::query()->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/agentstore/v1/pairing/verify', ['pairing_id' => self::PAIRING, 'approval_token' => $token, 'key_id' => $credentials->keyId, 'request_secret' => $credentials->requestSecret, 'webhook_secret' => $credentials->webhookSecret])
            ->assertForbidden();
    }

    public function test_weak_credentials_are_refused(): void
    {
        [$token] = $this->pendingApproval();

        $this->postJson('/agentstore/v1/pairing/verify', ['pairing_id' => self::PAIRING, 'approval_token' => $token, 'key_id' => 'ak_x', 'request_secret' => 'short', 'webhook_secret' => 'short'])
            ->assertStatus(422);
        $this->assertNull(Connection::active());
    }

    public function test_completion_is_rate_limited(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/agentstore/v1/pairing/verify', ['pairing_id' => self::PAIRING]);
        }

        $this->postJson('/agentstore/v1/pairing/verify', ['pairing_id' => self::PAIRING])->assertStatus(429);
    }

    /** @return array{0: string, 1: Credentials} */
    private function pendingApproval(): array
    {
        $token = str_repeat('t', 64);
        $pairing = new Pairing;
        $pairing->forceFill([
            'pairing_id' => self::PAIRING,
            'token_hash' => hash('sha256', $token),
            'capabilities' => [],
            'tenant_label' => 'Space Gift Shop',
            'expires_at' => now()->addMinutes(10),
        ])->save();

        return [$token, Credentials::generate()];
    }
}
