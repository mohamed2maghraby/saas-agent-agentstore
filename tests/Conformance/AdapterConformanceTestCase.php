<?php

namespace SaasAgent\AgentStore\Tests\Conformance;

use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Contract\OrderStatus;
use SaasAgent\AgentStore\Contract\PlacedOrder;
use SaasAgent\AgentStore\Contract\Price;
use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ProductPage;
use SaasAgent\AgentStore\Contract\StockLevel;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Tests\TestCase;

/**
 * The conformance suite. A store proves its adapters honour the contract by
 * extending this class and describing its own test data — nothing here knows
 * any store's schema.
 *
 * Every check goes through the real signed HTTP endpoints, and every response
 * is parsed back with the contract DTOs exactly as the SaaS parses it. A check
 * whose capability the store does not offer is skipped, not failed.
 *
 * To run it for your own store, copy one of the example tests
 * (StoreAConformanceTest, StoreBConformanceTest) into your application's tests
 * and fill in the methods below.
 */
abstract class AdapterConformanceTestCase extends TestCase
{
    protected Credentials $credentials;

    /** Configure `agentstore.adapters`, create the store's tables and seed data. */
    abstract protected function configureStore(): void;

    abstract protected function searchableText(): string;

    abstract protected function knownProductId(): string;

    abstract protected function knownVariantId(): string;

    /** A variant with less stock than oversizedOrder() asks for. */
    abstract protected function scarceVariantId(): string;

    abstract protected function validCustomer(): CustomerVerification;

    abstract protected function wrongCustomer(): CustomerVerification;

    abstract protected function ownOrderId(): string;

    /** An order that exists but belongs to someone else. */
    abstract protected function someoneElsesOrderId(): string;

    abstract protected function validOrder(): OrderRequest;

    abstract protected function oversizedOrder(): OrderRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureStore();
        $this->credentials = $this->pair();
    }

    private function needs(Capability $capability): void
    {
        $offered = $this->signed('GET', '/agentstore/v1/capabilities', null, $this->credentials)->json('data.capabilities');

        if (! in_array($capability->value, $offered, true)) {
            $this->markTestSkipped("The store does not offer {$capability->value}.");
        }
    }

    public function test_capabilities_match_the_configured_adapters(): void
    {
        $response = $this->signed('GET', '/agentstore/v1/capabilities', null, $this->credentials)->assertOk();

        $this->assertSame('v1', $response->json('data.contract_version'));

        foreach ($response->json('data.capabilities') as $capability) {
            $this->assertNotNull(config('agentstore.adapters.'.Capability::from($capability)->adapter()));
        }
    }

    public function test_search_returns_contract_valid_pages_that_end(): void
    {
        $this->needs(Capability::ProductsSearch);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = http_build_query(array_filter(['limit' => 2, 'cursor' => $cursor]));
            $page = ProductPage::fromArray($this->signed('GET', "/agentstore/v1/products?{$query}", null, $this->credentials)->assertOk()->json('data'));

            $this->assertLessThanOrEqual(2, count($page->items));
            foreach ($page->items as $product) {
                $this->assertNotContains($product->id, $seen, 'A product appeared on two pages.');
                $seen[] = $product->id;
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$pages < 50);

        $this->assertLessThan(50, $pages, 'Pagination did not terminate.');
        $this->assertNotEmpty($seen);
    }

    public function test_search_matches_text(): void
    {
        $this->needs(Capability::ProductsSearch);

        $text = $this->searchableText();
        $page = ProductPage::fromArray($this->signed('GET', '/agentstore/v1/products?text='.rawurlencode($text), null, $this->credentials)->assertOk()->json('data'));

        $this->assertNotEmpty($page->items);
        foreach ($page->items as $product) {
            $this->assertStringContainsStringIgnoringCase($text, $product->title);
        }
    }

    public function test_a_product_is_found_by_id_and_an_unknown_one_is_not(): void
    {
        $this->needs(Capability::ProductsRead);

        $product = Product::fromArray($this->signed('GET', '/agentstore/v1/products/'.rawurlencode($this->knownProductId()), null, $this->credentials)->assertOk()->json('data'));
        $this->assertSame($this->knownProductId(), $product->id);
        $this->assertNotEmpty($product->variants);

        $this->signed('GET', '/agentstore/v1/products/does-not-exist-'.bin2hex(random_bytes(4)), null, $this->credentials)
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_stock_is_reported_for_known_variants_and_unknown_ones_are_left_out(): void
    {
        $this->needs(Capability::InventoryRead);

        $items = $this->signed('POST', '/agentstore/v1/inventory', ['variant_ids' => [$this->knownVariantId(), 'unknown-variant']], $this->credentials)
            ->assertOk()->json('data.items');

        $levels = array_map(fn ($i) => StockLevel::fromArray($i), $items);
        $this->assertSame([$this->knownVariantId()], array_map(fn (StockLevel $l) => $l->variantId, $levels));
    }

    public function test_prices_are_money_in_minor_units(): void
    {
        $this->needs(Capability::PricesRead);

        $items = $this->signed('POST', '/agentstore/v1/prices', ['variant_ids' => [$this->knownVariantId()]], $this->credentials)
            ->assertOk()->json('data.items');

        $price = Price::fromArray($items[0]);
        $this->assertSame($this->knownVariantId(), $price->variantId);
        $this->assertIsInt($price->price->amount);
    }

    public function test_verification_succeeds_only_with_the_right_evidence(): void
    {
        $this->needs(Capability::CustomersVerify);

        $this->signed('POST', '/agentstore/v1/customers/verify', $this->wrongCustomer()->toArray(), $this->credentials)
            ->assertOk()->assertJsonPath('data.verified', false)->assertJsonMissingPath('data.customer_token');

        $this->assertIsString($this->customerToken());
    }

    public function test_a_customer_sees_their_own_order_and_not_someone_elses(): void
    {
        $this->needs(Capability::OrdersRead);
        $token = $this->customerToken();

        $own = $this->signed('POST', '/agentstore/v1/orders/lookup', ['order_id' => $this->ownOrderId(), 'customer_token' => $token], $this->credentials)->assertOk();
        $this->assertSame($this->ownOrderId(), OrderStatus::fromArray($own->json('data'))->id);

        // Ownership is the store's check, and a stranger's order looks like no order.
        $this->signed('POST', '/agentstore/v1/orders/lookup', ['order_id' => $this->someoneElsesOrderId(), 'customer_token' => $token], $this->credentials)
            ->assertNotFound();
    }

    public function test_order_reads_require_a_store_issued_customer_token(): void
    {
        $this->needs(Capability::OrdersRead);

        $this->signed('POST', '/agentstore/v1/orders/lookup', ['order_id' => $this->ownOrderId()], $this->credentials)
            ->assertStatus(401)->assertJsonPath('error.code', 'customer_verification_required');

        $this->signed('POST', '/agentstore/v1/orders/lookup', ['order_id' => $this->ownOrderId(), 'customer_token' => 'forged'], $this->credentials)
            ->assertStatus(401);
    }

    public function test_a_customer_token_is_bound_to_the_pairing_that_obtained_it(): void
    {
        $this->needs(Capability::OrdersRead);
        $token = $this->customerToken();

        // Re-pairing replaces the credentials; the old token must stop working.
        $this->credentials = $this->pair();

        $this->signed('POST', '/agentstore/v1/orders/lookup', ['order_id' => $this->ownOrderId(), 'customer_token' => $token], $this->credentials)
            ->assertStatus(401);
    }

    public function test_listing_returns_only_the_customers_orders(): void
    {
        $this->needs(Capability::OrdersList);

        $items = $this->signed('POST', '/agentstore/v1/orders/list', ['customer_token' => $this->customerToken(), 'limit' => 20], $this->credentials)
            ->assertOk()->json('data.items');

        $ids = array_map(fn ($o) => OrderStatus::fromArray($o)->id, $items);
        $this->assertContains($this->ownOrderId(), $ids);
        $this->assertNotContains($this->someoneElsesOrderId(), $ids);
    }

    public function test_an_order_is_placed_once_per_idempotency_key(): void
    {
        $this->needs(Capability::OrdersCreate);
        $body = ['idempotency_key' => 'conformance-'.bin2hex(random_bytes(6)), 'order' => $this->validOrder()->toArray()];

        $first = $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertCreated();
        $placed = PlacedOrder::fromArray($first->json('data'));

        $again = $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($placed->id, PlacedOrder::fromArray($again->json('data'))->id, 'A retry placed a second order.');

        $lookup = $this->signed('POST', '/agentstore/v1/idempotency/lookup', ['operation' => 'orders.create', 'idempotency_key' => $body['idempotency_key']], $this->credentials)
            ->assertOk();
        $this->assertSame('completed', $lookup->json('data.status'));
        $this->assertSame($placed->id, $lookup->json('data.response.data.id'));
    }

    public function test_reusing_a_key_for_a_different_order_is_refused(): void
    {
        $this->needs(Capability::OrdersCreate);
        $key = 'conformance-'.bin2hex(random_bytes(6));

        $this->signed('POST', '/agentstore/v1/orders', ['idempotency_key' => $key, 'order' => $this->validOrder()->toArray()], $this->credentials)->assertCreated();

        $different = $this->validOrder()->toArray();
        $different['notes'] = 'changed';
        $this->signed('POST', '/agentstore/v1/orders', ['idempotency_key' => $key, 'order' => $different], $this->credentials)
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_an_order_beyond_stock_is_refused_and_the_refusal_is_stable(): void
    {
        $this->needs(Capability::OrdersCreate);
        $body = ['idempotency_key' => 'conformance-'.bin2hex(random_bytes(6)), 'order' => $this->oversizedOrder()->toArray()];

        $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertStatus(422)->assertJsonPath('error.code', 'out_of_stock');
        // The refusal is the recorded outcome for that key.
        $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
    }

    protected function customerToken(): string
    {
        $response = $this->signed('POST', '/agentstore/v1/customers/verify', $this->validCustomer()->toArray(), $this->credentials)
            ->assertOk()->assertJsonPath('data.verified', true);

        return $response->json('data.customer_token');
    }
}
