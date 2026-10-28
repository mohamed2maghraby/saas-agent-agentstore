<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use Illuminate\Support\Facades\Http;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Tests\TestCase;

class RotationAndRevocationTest extends TestCase
{
    public function test_rotation_switches_to_the_new_secret_and_proves_it(): void
    {
        $old = $this->pair();
        $new = Credentials::generate();

        $response = $this->signed('POST', '/agentstore/v1/credentials/rotate', [
            'key_id' => $new->keyId, 'request_secret' => $new->requestSecret, 'webhook_secret' => $new->webhookSecret,
        ], $old)->assertOk();

        $this->assertSame(Signature::proof($new->requestSecret, 'rotate', $new->keyId), $response->json('data.proof'));
        $this->signed('GET', '/agentstore/v1/health', null, $new)->assertOk();
    }

    public function test_the_old_secret_works_only_during_the_grace_window(): void
    {
        $old = $this->pair();
        $new = Credentials::generate();
        Connection::active()->rotate($new);

        $this->signed('GET', '/agentstore/v1/health', null, $old)->assertOk();

        $this->travel(11)->minutes();
        $this->signed('GET', '/agentstore/v1/health', null, $old)->assertStatus(401);
        $this->signed('GET', '/agentstore/v1/health', null, $new)->assertOk();
    }

    public function test_revocation_locks_the_saas_out(): void
    {
        $credentials = $this->pair();

        $this->signed('DELETE', '/agentstore/v1/connection', null, $credentials)->assertOk();

        $this->assertNull(Connection::active());
        $this->signed('GET', '/agentstore/v1/health', null, $credentials)->assertStatus(401);
    }

    public function test_the_disconnect_command_deletes_the_credentials(): void
    {
        Http::fake(['https://saas.test/*' => Http::response(['accepted' => []])]);
        $credentials = $this->pair();

        $this->artisan('agentstore:disconnect', ['--force' => true])->assertSuccessful();

        $this->assertNull(Connection::active());
        $this->signed('GET', '/agentstore/v1/health', null, $credentials)->assertStatus(401);
    }
}
