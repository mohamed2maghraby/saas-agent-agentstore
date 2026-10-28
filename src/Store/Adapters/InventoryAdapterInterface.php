<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\StockLevel;

/**
 * Current availability. Provides `inventory.read`.
 *
 * Read the live figure the store's checkout would use, including any safety
 * stock it holds back. An id the store does not recognise is left out of the
 * result rather than reported as out of stock.
 */
interface InventoryAdapterInterface
{
    /**
     * @param  list<string>  $variantIds  at most 100
     * @return list<StockLevel>
     */
    public function stock(array $variantIds): array;
}
