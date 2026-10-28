<?php

namespace SaasAgent\AgentStore\Tests\Fixtures\StoreA;

use Illuminate\Support\Facades\DB;
use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\Money;
use SaasAgent\AgentStore\Contract\OrderLine;
use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Contract\OrderState;
use SaasAgent\AgentStore\Contract\OrderStatus;
use SaasAgent\AgentStore\Contract\PlacedOrder;
use SaasAgent\AgentStore\Contract\Price;
use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ProductPage;
use SaasAgent\AgentStore\Contract\ProductQuery;
use SaasAgent\AgentStore\Contract\StockLevel;
use SaasAgent\AgentStore\Contract\StockStatus;
use SaasAgent\AgentStore\Contract\Variant;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;
use SaasAgent\AgentStore\Store\Adapters\CustomerAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\InventoryAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderPlacementAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\PricingAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;
use SaasAgent\AgentStore\Store\StoreRefusal;

/**
 * Store A's adapters. Everything below is store-specific mapping — the package
 * supplies none of it. Variant id = "a-{product id}".
 */
final class CatalogAdapter implements InventoryAdapterInterface, PricingAdapterInterface, ProductAdapterInterface
{
    private const LOW = 3;

    public function search(ProductQuery $query): ProductPage
    {
        $offset = (int) ($query->cursor ?? 0);

        $rows = AProduct::query()
            ->where('is_active', true)
            ->when($query->text, fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($query->category, fn ($q, $c) => $q->where('category', $c))
            ->when($query->maxPrice !== null, fn ($q) => $q->whereRaw('coalesce(sale_price, price) * 100 <= ?', [$query->maxPrice]))
            ->when($query->inStockOnly, fn ($q) => $q->where('stock_quantity', '>', 0))
            ->orderBy('id')
            ->offset($offset)
            ->limit($query->limit + 1)
            ->get();

        $more = $rows->count() > $query->limit;

        return new ProductPage(
            $rows->take($query->limit)->map(fn (AProduct $p) => $this->product($p))->values()->all(),
            $more ? (string) ($offset + $query->limit) : null,
        );
    }

    public function find(string $productId): ?Product
    {
        $product = AProduct::query()->where('is_active', true)->find(self::id($productId));

        return $product ? $this->product($product) : null;
    }

    public function stock(array $variantIds): array
    {
        return AProduct::query()->whereKey(array_filter(array_map([self::class, 'id'], $variantIds)))->get()
            ->map(fn (AProduct $p) => new StockLevel('a-'.$p->id, self::status($p->stock_quantity), $p->stock_quantity))
            ->values()->all();
    }

    public function prices(array $variantIds): array
    {
        return AProduct::query()->whereKey(array_filter(array_map([self::class, 'id'], $variantIds)))->get()
            ->map(fn (AProduct $p) => new Price(
                'a-'.$p->id,
                Money::fromDecimal($p->sale_price ?? $p->price, 'EGP'),
                $p->sale_price !== null ? Money::fromDecimal($p->price, 'EGP') : null,
            ))
            ->values()->all();
    }

    private function product(AProduct $p): Product
    {
        return new Product(
            id: 'a-'.$p->id,
            title: $p->name,
            variants: [new Variant('a-'.$p->id, $p->name, null, [], Money::fromDecimal($p->sale_price ?? $p->price, 'EGP'), self::status($p->stock_quantity))],
            description: $p->description,
            categories: array_filter([$p->category]),
        );
    }

    private static function status(int $quantity): StockStatus
    {
        return match (true) {
            $quantity <= 0 => StockStatus::OutOfStock,
            $quantity <= self::LOW => StockStatus::LowStock,
            default => StockStatus::InStock,
        };
    }

    public static function id(string $variantId): ?int
    {
        return preg_match('/\Aa-(\d+)\z/', $variantId, $m) ? (int) $m[1] : null;
    }
}

/** Store A knows a customer only by the phone on their orders. */
final class CustomerAdapter implements CustomerAdapterInterface
{
    public function verify(CustomerVerification $verification): ?VerifiedCustomer
    {
        // Policy: the phone must match the phone on the order number given.
        if (! $verification->phone || ! $verification->orderNumber) {
            return null;
        }

        $order = AOrder::query()->where('number', $verification->orderNumber)->first();

        return $order && $order->customer_phone === $verification->phone
            ? new VerifiedCustomer($order->customer_phone, $order->customer_name)
            : null;
    }
}

final class OrderAdapter implements OrderAdapterInterface, OrderPlacementAdapterInterface
{
    private const STATES = [
        'new' => OrderState::Pending,
        'paid' => OrderState::Confirmed,
        'shipped' => OrderState::Shipped,
        'done' => OrderState::Delivered,
        'cancelled' => OrderState::Cancelled,
    ];

    public function find(string $orderId, VerifiedCustomer $customer): ?OrderStatus
    {
        $order = AOrder::query()->with('items')->where('customer_phone', $customer->ref)->find((int) ltrim($orderId, 'o'));

        return $order ? $this->status($order) : null;
    }

    public function forCustomer(VerifiedCustomer $customer, int $limit): array
    {
        return AOrder::query()->with('items')->where('customer_phone', $customer->ref)->latest('id')->limit($limit)->get()
            ->map(fn (AOrder $o) => $this->status($o))->all();
    }

    /** Store A's "checkout": price from the catalog, decrement stock, all in one transaction. */
    public function place(OrderRequest $request): PlacedOrder
    {
        return DB::transaction(function () use ($request) {
            $lines = [];
            $total = 0.0;

            foreach ($request->items as $item) {
                $product = AProduct::query()->lockForUpdate()->find(CatalogAdapter::id($item['variant_id']));

                if (! $product || ! $product->is_active) {
                    throw StoreRefusal::invalid('Unknown item.', ['variant_id' => $item['variant_id']]);
                }

                if ($product->stock_quantity < $item['quantity']) {
                    throw StoreRefusal::outOfStock(details: ['variant_id' => $item['variant_id']]);
                }

                $unit = (float) ($product->sale_price ?? $product->price);
                $product->decrement('stock_quantity', $item['quantity']);
                $lines[] = ['product_id' => $product->id, 'qty' => $item['quantity'], 'unit_price' => $unit];
                $total += $unit * $item['quantity'];
            }

            $order = AOrder::query()->create([
                'number' => 'A'.str_pad((string) (AOrder::query()->count() + 1001), 6, '0', STR_PAD_LEFT),
                'customer_name' => $request->customerName,
                'customer_phone' => $request->customerPhone,
                'status' => 'new',
                'total' => $total,
                'city' => $request->address['city'] ?? '',
            ]);
            $order->items()->createMany($lines);

            return new PlacedOrder('o'.$order->id, $order->number, OrderState::Pending, Money::fromDecimal($total, 'EGP'));
        });
    }

    private function status(AOrder $order): OrderStatus
    {
        return new OrderStatus(
            id: 'o'.$order->id,
            number: $order->number,
            state: self::STATES[$order->status] ?? OrderState::Unknown,
            storeStatus: $order->status,
            total: Money::fromDecimal($order->total, 'EGP'),
            lines: $order->items->map(fn (AOrderItem $i) => new OrderLine('a-'.$i->product_id, 'Item', $i->qty, Money::fromDecimal($i->unit_price, 'EGP')))->all(),
            placedAt: $order->created_at,
            cancellable: $order->status === 'new',
        );
    }
}
