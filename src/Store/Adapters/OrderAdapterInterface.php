<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\OrderStatus;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;

/**
 * Reads orders — always for a verified customer. Provides `orders.read` and
 * `orders.list`.
 *
 * Both methods receive the customer the store verified, and must only return
 * that customer's orders: an order id alone is not proof of ownership.
 */
interface OrderAdapterInterface
{
    public function find(string $orderId, VerifiedCustomer $customer): ?OrderStatus;

    /** @return list<OrderStatus> newest first, at most $limit */
    public function forCustomer(VerifiedCustomer $customer, int $limit): array;
}
