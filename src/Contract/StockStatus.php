<?php

namespace SaasAgent\AgentStore\Contract;

enum StockStatus: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';
    // The store could not say — a made-to-order item, or a stock service that is down.
    case Unknown = 'unknown';

    public function sellable(): bool
    {
        return $this === self::InStock || $this === self::LowStock;
    }
}
