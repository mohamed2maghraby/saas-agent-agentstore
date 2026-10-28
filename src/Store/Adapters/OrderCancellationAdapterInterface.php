<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\CancellationResult;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;

/**
 * Cancels an order under the store's own policy. Provides `orders.cancel`.
 *
 * Return CancellationResult::refused() when the policy says no (already shipped,
 * past the window). Like placement, it runs at most once per idempotency key.
 */
interface OrderCancellationAdapterInterface
{
    public function cancel(string $orderId, VerifiedCustomer $customer, ?string $reason): CancellationResult;
}
