<?php

namespace SaasAgent\AgentStore\Tests\Conformance;

use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\AOrder;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\AProduct;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\CatalogAdapter;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\CustomerAdapter;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\OrderAdapter;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\StoreA;

/** Store A ("Classic") against the conformance suite, plus what is specific to it. */
class StoreAConformanceTest extends AdapterConformanceTestCase
{
    private int $mug;

    private int $scarce;

    private AOrder $mine;

    private AOrder $theirs;

    protected function configureStore(): void
    {
        StoreA::migrate();

        $this->useAdapters([
            'products' => CatalogAdapter::class,
            'inventory' => CatalogAdapter::class,
            'pricing' => CatalogAdapter::class,
            'customers' => CustomerAdapter::class,
            'orders' => OrderAdapter::class,
            'order_placement' => OrderAdapter::class,
        ]);

        $this->mug = AProduct::query()->create(['name' => 'Soft Bow Mug', 'category' => 'mugs', 'price' => 540, 'stock_quantity' => 10])->id;
        AProduct::query()->create(['name' => 'Fairy Notebook', 'category' => 'stationery', 'price' => 200, 'sale_price' => 175.5, 'stock_quantity' => 3]);
        AProduct::query()->create(['name' => 'Gift Box', 'category' => 'gifts', 'price' => 900, 'stock_quantity' => 0]);
        AProduct::query()->create(['name' => 'Hidden Draft Mug', 'category' => 'mugs', 'price' => 1, 'stock_quantity' => 99, 'is_active' => false]);
        $this->scarce = AProduct::query()->create(['name' => 'Last Candle', 'category' => 'candles', 'price' => 120, 'stock_quantity' => 1])->id;

        $this->mine = AOrder::query()->create(['number' => 'A000900', 'customer_name' => 'Mona', 'customer_phone' => '01000000001', 'status' => 'shipped', 'total' => 540, 'city' => 'Cairo']);
        $this->mine->items()->create(['product_id' => $this->mug, 'qty' => 1, 'unit_price' => 540]);
        $this->theirs = AOrder::query()->create(['number' => 'A000901', 'customer_name' => 'Omar', 'customer_phone' => '01000000002', 'status' => 'new', 'total' => 200, 'city' => 'Giza']);
    }

    protected function searchableText(): string
    {
        return 'mug';
    }

    protected function knownProductId(): string
    {
        return 'a-'.$this->mug;
    }

    protected function knownVariantId(): string
    {
        return 'a-'.$this->mug;
    }

    protected function scarceVariantId(): string
    {
        return 'a-'.$this->scarce;
    }

    protected function validCustomer(): CustomerVerification
    {
        return new CustomerVerification(phone: '01000000001', orderNumber: 'A000900');
    }

    protected function wrongCustomer(): CustomerVerification
    {
        return new CustomerVerification(phone: '01000000009', orderNumber: 'A000900');
    }

    protected function ownOrderId(): string
    {
        return 'o'.$this->mine->id;
    }

    protected function someoneElsesOrderId(): string
    {
        return 'o'.$this->theirs->id;
    }

    protected function validOrder(): OrderRequest
    {
        return new OrderRequest([['variant_id' => $this->knownVariantId(), 'quantity' => 2]], 'Mona', '01000000001', ['city' => 'Cairo', 'street' => '9 Nile St'], 'cod');
    }

    protected function oversizedOrder(): OrderRequest
    {
        return new OrderRequest([['variant_id' => $this->scarceVariantId(), 'quantity' => 5]], 'Mona', '01000000001', ['city' => 'Cairo'], 'cod');
    }

    public function test_hidden_products_are_never_offered(): void
    {
        $titles = array_column($this->signed('GET', '/agentstore/v1/products?text=mug', null, $this->credentials)->json('data.items'), 'title');

        $this->assertContains('Soft Bow Mug', $titles);
        $this->assertNotContains('Hidden Draft Mug', $titles);
    }

    public function test_the_sale_price_is_the_price_and_decimals_become_minor_units(): void
    {
        $notebook = AProduct::query()->where('name', 'Fairy Notebook')->first();
        $item = $this->signed('POST', '/agentstore/v1/prices', ['variant_ids' => ['a-'.$notebook->id]], $this->credentials)->json('data.items.0');

        $this->assertSame(17550, $item['price']['amount']);
        $this->assertSame(20000, $item['compare_at']['amount']);
    }

    public function test_placing_through_the_store_prices_it_and_takes_stock_once(): void
    {
        $body = ['idempotency_key' => 'store-a-order-1', 'order' => $this->validOrder()->toArray()];

        $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertCreated()->assertJsonPath('data.total.amount', 108000);
        $this->signed('POST', '/agentstore/v1/orders', $body, $this->credentials)->assertCreated();

        $this->assertSame(8, AProduct::query()->find($this->mug)->stock_quantity, 'Stock was taken more than once.');
    }
}
