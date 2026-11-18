<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Category;

/**
 * The categories a shopper can browse, as the store's menu shows them.
 * Provides `categories.list`. At most 200 are passed on; a product search
 * takes a category's `id`.
 */
interface CategoryAdapterInterface
{
    /** @return list<Category> */
    public function categories(): array;
}
