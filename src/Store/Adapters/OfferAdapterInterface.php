<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Offer;

/**
 * The offers running now, as the storefront shows them. Provides `offers.list`.
 * At most 50 are passed on.
 */
interface OfferAdapterInterface
{
    /** @return list<Offer> */
    public function running(): array;
}
