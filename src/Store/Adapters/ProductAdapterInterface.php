<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ProductPage;
use SaasAgent\AgentStore\Contract\ProductQuery;

/**
 * Reads the store's catalog. Provides `products.search` and `products.read`.
 *
 * Return only what a shopper may see: hidden, draft or wholesale-only products
 * must not appear, however the store marks them.
 */
interface ProductAdapterInterface
{
    public function search(ProductQuery $query): ProductPage;

    /** Null when the product does not exist or is not for sale. */
    public function find(string $productId): ?Product;
}
