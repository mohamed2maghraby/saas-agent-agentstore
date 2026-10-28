<?php

namespace SaasAgent\AgentStore\Tests\Fixtures\StoreB;

use DomainException;
use SaasAgent\AgentStore\Contract\CancellationResult;
use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\ErrorCode;
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
use SaasAgent\AgentStore\Store\Adapters\OrderCancellationAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderPlacementAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\PricingAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;
use SaasAgent\AgentStore\Store\StoreRefusal;

/** Store B's catalog: products are items, variants are options, ids are uuids. */
final class CraftCatalog implements PricingAdapterInterface, ProductAdapterInterface
{
    public function search(ProductQuery $query): ProductPage
    {
        $items = BItem::query()->with('options')
            ->where('listed', true)
            ->when($query->text, fn ($q, $t) => $q->where('title', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($query->category, fn ($q, $c) => $q->where('collection', $c))
            ->when($query->cursor, fn ($q, $c) => $q->where('id', '>', $c))
            ->orderBy('id')
            ->limit($query->limit + 1)
            ->get()
            ->filter(fn (BItem $i) => $query->maxPrice === null || $i->options->min('price_piastres') <= $query->maxPrice)
            ->values();

        $page = $items->take($query->limit);

        return new ProductPage(
            $page->map(fn (BItem $i) => $this->product($i))->all(),
            $items->count() > $query->limit ? $page->last()->id : null,
        );
    }

    public function find(string $productId): ?Product
    {
        $item = BItem::query()->with('options')->where('listed', true)->find($productId);

        return $item && $item->options->isNotEmpty() ? $this->product($item) : null;
    }

    public function prices(array $variantIds): array
    {
        return BItemOption::query()->whereKey($variantIds)->get()
            ->map(fn (BItemOption $o) => new Price($o->id, new Money($o->price_piastres, 'EGP')))
            ->values()->all();
    }

    private function product(BItem $item): Product
    {
        return new Product(
            id: $item->id,
            title: $item->title,
            variants: $item->options->map(fn (BItemOption $o) => new Variant($o->id, $o->label, $o->sku, ['Option' => $o->label], new Money($o->price_piastres, 'EGP')))->all(),
            categories: [$item->collection],
        );
    }
}

/** Stock comes from the store's InventoryService, never from a column. */
final class CraftInventory implements InventoryAdapterInterface
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function stock(array $variantIds): array
    {
        $known = BItemOption::query()->whereKey($variantIds)->pluck('id')->all();
        $sellable = $this->inventory->sellable($known);

        return array_map(function (string $id) use ($sellable) {
            $qty = $sellable[$id] ?? 0;

            // Store B does not disclose quantities.
            return new StockLevel($id, $qty <= 0 ? StockStatus::OutOfStock : ($qty < 5 ? StockStatus::LowStock : StockStatus::InStock));
        }, $known);
    }
}

/** Store B verifies by the mobile number registered on the customer account. */
final class CraftCustomers implements CustomerAdapterInterface
{
    public function verify(CustomerVerification $verification): ?VerifiedCustomer
    {
        $mobile = $verification->channelIdentity ?? $verification->phone;
        $customer = $mobile ? BCustomer::query()->where('mobile', $mobile)->first() : null;

        return $customer ? new VerifiedCustomer((string) $customer->id, $customer->full_name) : null;
    }
}

final class CraftOrders implements OrderAdapterInterface, OrderCancellationAdapterInterface, OrderPlacementAdapterInterface
{
    private const STATES = [
        0 => OrderState::Pending,
        1 => OrderState::Confirmed,
        2 => OrderState::Processing,
        3 => OrderState::Shipped,
        4 => OrderState::Delivered,
        9 => OrderState::Cancelled,
    ];

    public function __construct(private readonly Checkout $checkout) {}

    public function find(string $orderId, VerifiedCustomer $customer): ?OrderStatus
    {
        $sale = BSale::query()->with('lines')->where('customer_id', (int) $customer->ref)->where('ref', $orderId)->first();

        return $sale ? $this->status($sale) : null;
    }

    public function forCustomer(VerifiedCustomer $customer, int $limit): array
    {
        return BSale::query()->with('lines')->where('customer_id', (int) $customer->ref)->latest('id')->limit($limit)->get()
            ->map(fn (BSale $s) => $this->status($s))->all();
    }

    /** Hands the order to the store's Checkout — the adapter computes nothing itself. */
    public function place(OrderRequest $request): PlacedOrder
    {
        $customer = BCustomer::query()->firstOrCreate(['mobile' => $request->customerPhone], ['full_name' => $request->customerName, 'email' => $request->customerEmail]);

        try {
            ['sale' => $sale, 'total' => $total] = $this->checkout->placeSale(
                $customer,
                array_map(fn ($i) => ['option_id' => $i['variant_id'], 'quantity' => $i['quantity']], $request->items),
                $request->address['city'] ?? '',
            );
        } catch (DomainException $e) {
            [$reason, $id] = explode(':', $e->getMessage(), 2) + [1 => null];

            throw $reason === 'insufficient_stock'
                ? StoreRefusal::outOfStock(details: ['variant_id' => $id])
                : StoreRefusal::invalid('Unknown item.', ['variant_id' => $id]);
        }

        return new PlacedOrder($sale->ref, $sale->ref, self::STATES[$sale->state], new Money($total, 'EGP'));
    }

    /** Store policy: only before it is shipped. */
    public function cancel(string $orderId, VerifiedCustomer $customer, ?string $reason): CancellationResult
    {
        $sale = BSale::query()->where('customer_id', (int) $customer->ref)->where('ref', $orderId)->first();

        if (! $sale) {
            throw new StoreRefusal(ErrorCode::NotFound, 'Order not found.');
        }

        if ($sale->state >= 3) {
            return CancellationResult::refused(self::STATES[$sale->state], 'Already shipped.');
        }

        $sale->update(['state' => 9]);

        return CancellationResult::cancelled();
    }

    private function status(BSale $sale): OrderStatus
    {
        $state = self::STATES[$sale->state] ?? OrderState::Unknown;

        return new OrderStatus(
            id: $sale->ref,
            number: $sale->ref,
            state: $state,
            storeStatus: 'code '.$sale->state,
            total: new Money($sale->grand_total_piastres, 'EGP'),
            lines: $sale->lines->map(fn (BSaleLine $l) => new OrderLine($l->option_id, 'Option', $l->quantity, new Money($l->unit_piastres, 'EGP')))->all(),
            placedAt: $sale->created_at,
            cancellable: $sale->state < 3,
        );
    }
}
