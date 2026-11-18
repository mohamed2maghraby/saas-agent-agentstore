<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\ShippingEstimate;
use SaasAgent\AgentStore\Contract\ShippingQuery;

/**
 * What delivery to a place costs and takes, before there is an order.
 * Provides `shipping.estimate`.
 *
 * Answer `available: false` for a place the store does not deliver to. Throw
 * StoreRefusal::invalid() (field `city`) for a place it cannot make out, so the
 * assistant asks again rather than guessing between two.
 */
interface ShippingAdapterInterface
{
    public function estimate(ShippingQuery $query): ShippingEstimate;
}
