<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\OrderQuote;
use SaasAgent\AgentStore\Contract\OrderQuoteRequest;
use SaasAgent\AgentStore\Store\StoreRefusal;

/**
 * Prices an order without placing it. Provides `orders.quote`.
 *
 * Run the same pricing the checkout runs — offers, gifts, coupon, delivery to
 * this address, payment fees — and leave nothing behind: no cart, no order, no
 * reserved stock. Throw StoreRefusal for what would stop the order (an item out
 * of stock, an address the store does not deliver to, a payment method it does
 * not take), so the shopper hears it before they confirm, not after.
 */
interface OrderQuoteAdapterInterface
{
    /** @throws StoreRefusal */
    public function quote(OrderQuoteRequest $request): OrderQuote;
}
