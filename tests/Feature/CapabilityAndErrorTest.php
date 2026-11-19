<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use RuntimeException;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ProductPage;
use SaasAgent\AgentStore\Contract\ProductQuery;
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\StoreCapabilityInterface;
use SaasAgent\AgentStore\Tests\TestCase;

class ExplodingCatalog implements ProductAdapterInterface
{
    public function search(ProductQuery $query): ProductPage
    {
        throw new RuntimeException('SQLSTATE[42S02]: table secret_internal_prices not found in /var/www/app/Secret.php');
    }

    public function find(string $productId): ?Product
    {
        return null;
    }
}

class NoSearchPolicy implements StoreCapabilityInterface
{
    public function allows(Capability $capability): bool
    {
        return $capability !== Capability::ProductsSearch;
    }
}

class CapabilityAndErrorTest extends TestCase
{
    public function test_an_unconfigured_capability_is_unsupported(): void
    {
        $this->useAdapters([]);

        $this->signed('POST', '/agentstore/v1/orders', ['idempotency_key' => 'k-12345678', 'order' => []])
            ->assertStatus(501)->assertJsonPath('error.code', 'unsupported_capability');

        $this->signed('GET', '/agentstore/v1/capabilities')->assertJsonPath('data.capabilities', []);
    }

    public function test_a_disabled_capability_is_unsupported_even_with_an_adapter(): void
    {
        $this->useAdapters(['products' => ExplodingCatalog::class]);
        config(['agentstore.disabled_capabilities' => ['products.search']]);

        $this->signed('GET', '/agentstore/v1/products')->assertStatus(501);
        $this->assertNotContains('products.search', $this->signed('GET', '/agentstore/v1/capabilities')->json('data.capabilities'));
    }

    public function test_the_runtime_policy_can_switch_a_capability_off(): void
    {
        $this->useAdapters(['products' => ExplodingCatalog::class]);
        config(['agentstore.capability_policy' => NoSearchPolicy::class]);

        $this->signed('GET', '/agentstore/v1/products')->assertStatus(501);
        $this->signed('GET', '/agentstore/v1/products/x')->assertNotFound();
    }

    public function test_a_store_exception_never_reaches_the_saas(): void
    {
        $this->useAdapters(['products' => ExplodingCatalog::class]);

        $response = $this->signed('GET', '/agentstore/v1/products')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'store_error');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('Secret.php', $response->getContent());
    }

    public function test_a_contract_violation_names_the_field(): void
    {
        $this->useAdapters(['products' => ExplodingCatalog::class]);

        $this->signed('GET', '/agentstore/v1/products?limit=500')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }
}
