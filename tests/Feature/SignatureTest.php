<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Tests\TestCase;

/**
 * Every way a request can fail authentication, and that they all look alike
 * from outside — the response never says which check failed.
 */
class SignatureTest extends TestCase
{
    private Credentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials = $this->pair();
    }

    private function assertRejected($response): void
    {
        $response->assertStatus(401)->assertExactJson(['error' => [
            'code' => 'unauthenticated',
            'message' => 'The request could not be authenticated.',
            'retryable' => false,
        ]]);
    }

    public function test_a_correctly_signed_request_is_admitted(): void
    {
        $this->signed('GET', '/agentstore/v1/health', null, $this->credentials)->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->assertRejected($this->getJson('/agentstore/v1/health'));
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $impostor = new Credentials($this->credentials->keyId, str_repeat('x', 43), str_repeat('y', 43));

        $this->assertRejected($this->signed('GET', '/agentstore/v1/health', null, $impostor));
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $this->assertRejected($this->signed('GET', '/agentstore/v1/health', null, Credentials::generate()));
    }

    public function test_a_stale_or_future_timestamp_is_rejected(): void
    {
        $this->assertRejected($this->signed('GET', '/agentstore/v1/health', null, $this->credentials, timestamp: time() - 301));
        $this->assertRejected($this->signed('GET', '/agentstore/v1/health', null, $this->credentials, timestamp: time() + 301));
    }

    public function test_a_replayed_request_is_rejected(): void
    {
        $headers = Signature::headers($this->credentials->keyId, $this->credentials->requestSecret, 'GET', '/agentstore/v1/health', [], '');
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call('GET', '/agentstore/v1/health', [], [], [], $server)->assertOk();
        $this->assertRejected($this->call('GET', '/agentstore/v1/health', [], [], [], $server));
    }

    public function test_a_tampered_body_path_or_query_is_rejected(): void
    {
        $headers = Signature::headers($this->credentials->keyId, $this->credentials->requestSecret, 'POST', '/agentstore/v1/inventory', [], '{"variant_ids":["a-1"]}');
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        // Same signature, different body.
        $this->assertRejected($this->call('POST', '/agentstore/v1/inventory', [], [], [], $server, '{"variant_ids":["a-2"]}'));

        // A signature for one path replayed on another.
        $this->assertRejected($this->call('POST', '/agentstore/v1/prices', [], [], [], $server, '{"variant_ids":["a-1"]}'));

        $get = Signature::headers($this->credentials->keyId, $this->credentials->requestSecret, 'GET', '/agentstore/v1/products', ['text' => 'mug'], '');
        $server = [];
        foreach ($get as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->assertRejected($this->call('GET', '/agentstore/v1/products?text=vase', [], [], [], $server));
    }

    public function test_a_malformed_nonce_is_rejected(): void
    {
        $this->assertRejected($this->signed('GET', '/agentstore/v1/health', null, $this->credentials, [Signature::NONCE_HEADER => 'short']));
    }

    public function test_the_query_is_canonical_whatever_order_it_was_built_in(): void
    {
        $this->assertSame(
            Signature::query(['b' => '2', 'a' => ['y' => 1, 'x' => 2]]),
            Signature::query(['a' => ['x' => 2, 'y' => 1], 'b' => '2']),
        );
    }
}
