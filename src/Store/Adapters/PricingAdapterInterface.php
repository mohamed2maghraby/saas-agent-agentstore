<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Price;

/**
 * Current unit prices, with item-level offers applied. Provides `prices.read`.
 *
 * Cart-level rules (bundles, coupons, shipping) are not prices; the store's own
 * checkout applies them when an order is placed.
 */
interface PricingAdapterInterface
{
    /**
     * @param  list<string>  $variantIds  at most 100
     * @return list<Price>
     */
    public function prices(array $variantIds): array;
}
