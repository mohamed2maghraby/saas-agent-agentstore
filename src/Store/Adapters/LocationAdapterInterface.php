<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Location;

/**
 * Where a shopper can buy in person: branches, or shops that sell the store's
 * products. Provides `locations.list`. `$city` narrows the list when given,
 * matched however the store sees fit (either language, any spelling); at most
 * 100 are passed on.
 */
interface LocationAdapterInterface
{
    /** @return list<Location> */
    public function locations(?string $city): array;
}
