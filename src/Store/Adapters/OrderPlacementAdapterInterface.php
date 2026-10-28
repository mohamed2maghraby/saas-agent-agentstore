<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Contract\PlacedOrder;
use SaasAgent\AgentStore\Store\StoreRefusal;

/**
 * Places an order through the store's own checkout. Provides `orders.create`.
 *
 * Call the code the store's checkout calls — the same pricing, offers, stock
 * reservation, shipping and tax. Creating an Order row directly is not placing
 * an order.
 *
 * The package wraps this call with idempotency: it runs at most once per
 * idempotency key. Throw StoreRefusal for a business "no" (out of stock, a
 * coupon that does not apply); anything else is treated as a store failure.
 */
interface OrderPlacementAdapterInterface
{
    /** @throws StoreRefusal */
    public function place(OrderRequest $request): PlacedOrder;
}
