<?php

namespace SaasAgent\AgentStore\Tests\Conformance;

use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\BCustomer;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\BItem;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\BItemOption;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\BSale;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\CraftCatalog;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\CraftCustomers;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\CraftInventory;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\CraftOrders;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\InventoryService;
use SaasAgent\AgentStore\Tests\Fixtures\StoreB\StoreB;

/** Store B ("Craft") — a different schema behind the same contract. */
class StoreBConformanceTest extends AdapterConformanceTestCase
{
    private BItem $vase;

    private BItemOption $blue;

    private BItemOption $scarce;

    private BSale $mine;

    private BSale $theirs;

    protected function configureStore(): void
    {
        StoreB::migrate();

        $this->useAdapters([
            'products' => CraftCatalog::class,
            'inventory' => CraftInventory::class,
            'pricing' => CraftCatalog::class,
            'customers' => CraftCustomers::class,
            'orders' => CraftOrders::class,
            'order_placement' => CraftOrders::class,
            'order_cancellation' => CraftOrders::class,
        ]);

        $stock = app(InventoryService::class);

        $this->vase = BItem::query()->create(['title' => 'Clay Vase', 'collection' => 'home']);
        $this->blue = $this->vase->options()->create(['sku' => 'VASE-BL', 'label' => 'Blue', 'price_piastres' => 30000]);
        $this->vase->options()->create(['sku' => 'VASE-RD', 'label' => 'Red', 'price_piastres' => 32000]);
        $stock->move($this->blue->id, 20, 'opening');

        $lamp = BItem::query()->create(['title' => 'Brass Lamp', 'collection' => 'home']);
        $this->scarce = $lamp->options()->create(['sku' => 'LAMP-1', 'label' => 'Standard', 'price_piastres' => 120000]);
        // Three on hand, two held back as safety stock: one sellable.
        $stock->move($this->scarce->id, 3, 'opening');

        BItem::query()->create(['title' => 'Unlisted Vase', 'collection' => 'home', 'listed' => false])
            ->options()->create(['sku' => 'VASE-X', 'label' => 'Any', 'price_piastres' => 100]);

        $mona = BCustomer::query()->create(['full_name' => 'Mona', 'mobile' => '+201000000001']);
        $omar = BCustomer::query()->create(['full_name' => 'Omar', 'mobile' => '+201000000002']);
        $this->mine = BSale::query()->create(['ref' => 'CR-MINE01', 'customer_id' => $mona->id, 'state' => 2, 'grand_total_piastres' => 30000]);
        $this->theirs = BSale::query()->create(['ref' => 'CR-THEIRS', 'customer_id' => $omar->id, 'state' => 1, 'grand_total_piastres' => 32000]);
    }

    protected function searchableText(): string
    {
        return 'vase';
    }

    protected function knownProductId(): string
    {
        return $this->vase->id;
    }

    protected function knownVariantId(): string
    {
        return $this->blue->id;
    }

    protected function scarceVariantId(): string
    {
        return $this->scarce->id;
    }

    protected function validCustomer(): CustomerVerification
    {
        // Store B trusts the identity the messaging channel vouches for.
        return new CustomerVerification(channelIdentity: '+201000000001');
    }

    protected function wrongCustomer(): CustomerVerification
    {
        return new CustomerVerification(channelIdentity: '+201099999999');
    }

    protected function ownOrderId(): string
    {
        return $this->mine->ref;
    }

    protected function someoneElsesOrderId(): string
    {
        return $this->theirs->ref;
    }

    protected function validOrder(): OrderRequest
    {
        return new OrderRequest([['variant_id' => $this->blue->id, 'quantity' => 3]], 'Mona', '+201000000001', ['city' => 'Alexandria'], 'cod');
    }

    protected function oversizedOrder(): OrderRequest
    {
        // Two would be on hand, but safety stock leaves only one to sell.
        return new OrderRequest([['variant_id' => $this->scarce->id, 'quantity' => 2]], 'Mona', '+201000000001', ['city' => 'Cairo'], 'cod');
    }

    public function test_the_total_is_the_stores_own_checkout_total(): void
    {
        // 3 × 300.00 = 900.00, 10% bundle discount → 810.00, + 50.00 delivery outside Cairo.
        $this->signed('POST', '/agentstore/v1/orders', ['idempotency_key' => 'store-b-order-1', 'order' => $this->validOrder()->toArray()], $this->credentials)
            ->assertCreated()
            ->assertJsonPath('data.total.amount', 86000)
            ->assertJsonPath('data.state', 'confirmed');
    }

    public function test_stock_status_honours_the_safety_stock_without_disclosing_quantities(): void
    {
        $item = $this->signed('POST', '/agentstore/v1/inventory', ['variant_ids' => [$this->scarce->id]], $this->credentials)->json('data.items.0');

        $this->assertSame('low_stock', $item['status']);
        $this->assertNull($item['quantity']);
    }

    public function test_cancellation_follows_the_store_policy_and_runs_once(): void
    {
        $token = $this->customerToken();
        $body = ['idempotency_key' => 'cancel-mine-1', 'order_id' => $this->mine->ref, 'customer_token' => $token, 'reason' => 'Changed my mind'];

        $this->signed('POST', '/agentstore/v1/orders/cancel', $body, $this->credentials)->assertOk()->assertJsonPath('data.cancelled', true);
        $this->signed('POST', '/agentstore/v1/orders/cancel', $body, $this->credentials)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(9, $this->mine->fresh()->state);

        $this->mine->update(['state' => 3]);
        $this->signed('POST', '/agentstore/v1/orders/cancel', ['idempotency_key' => 'cancel-mine-2'] + $body, $this->credentials)
            ->assertOk()->assertJsonPath('data.cancelled', false)->assertJsonPath('data.reason', 'Already shipped.');
    }

    public function test_someone_elses_order_cannot_be_cancelled(): void
    {
        $this->signed('POST', '/agentstore/v1/orders/cancel', [
            'idempotency_key' => 'cancel-theirs', 'order_id' => $this->theirs->ref, 'customer_token' => $this->customerToken(),
        ], $this->credentials)->assertNotFound();

        $this->assertSame(1, $this->theirs->fresh()->state);
    }
}
