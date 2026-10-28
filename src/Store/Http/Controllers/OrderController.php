<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Contract\OrderRequest;
use SaasAgent\AgentStore\Contract\OrderStatus;
use SaasAgent\AgentStore\Store\Adapters\CustomerAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderCancellationAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderPlacementAdapterInterface;
use SaasAgent\AgentStore\Store\Capabilities;
use SaasAgent\AgentStore\Store\CustomerToken;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\Idempotency\IdempotentWrite;

/**
 * Customers and orders.
 *
 * Every order read or cancellation needs a customer token from /customers/verify:
 * the store decides who a shopper is, and only then what they may see.
 * Placement and cancellation run through IdempotentWrite.
 */
final class OrderController extends ApiController
{
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly IdempotentWrite $idempotency,
    ) {}

    public function verify(Request $request, CustomerToken $tokens): JsonResponse
    {
        return $this->respond(function () use ($request, $tokens) {
            $customer = $this->capabilities->adapter('customers', CustomerAdapterInterface::class)
                ->verify(CustomerVerification::fromArray($this->body($request)));

            if (! $customer) {
                return ['verified' => false];
            }

            return [
                'verified' => true,
                'customer_token' => $tokens->seal($customer, (string) $request->attributes->get('agentstore.key_id')),
                'display_name' => $customer->displayName,
                'expires_in' => 60 * (int) config('agentstore.customer_token_ttl', 30),
            ];
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);
            $orderId = $body['order_id'] ?? null;

            if (! is_string($orderId) || $orderId === '' || strlen($orderId) > 191) {
                throw ContractException::field('order_id', 'a string');
            }

            $order = $this->orders()->find($orderId, $this->customer($request, $body));

            // Someone else's order and no order at all look the same.
            return $order ? $order->toArray() : ApiError::response(ErrorCode::NotFound);
        });
    }

    public function list(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);
            $limit = max(1, min(20, (int) ($body['limit'] ?? 5)));
            $orders = $this->orders()->forCustomer($this->customer($request, $body), $limit);

            return ['items' => array_map(fn (OrderStatus $o) => $o->toArray(), array_slice($orders, 0, $limit))];
        });
    }

    public function place(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);
            $key = $this->idempotencyKey($body);
            $order = OrderRequest::fromArray(is_array($body['order'] ?? null) ? $body['order'] : []);

            return $this->idempotency->run('orders.create', $key, $order->toArray(), function () use ($order) {
                $placed = $this->capabilities->adapter('order_placement', OrderPlacementAdapterInterface::class)->place($order);

                return [201, ['data' => $placed->toArray()]];
            });
        });
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);
            $key = $this->idempotencyKey($body);
            $orderId = $body['order_id'] ?? null;

            if (! is_string($orderId) || $orderId === '' || strlen($orderId) > 191) {
                throw ContractException::field('order_id', 'a string');
            }

            $customer = $this->customer($request, $body);
            $reason = isset($body['reason']) && is_string($body['reason']) ? mb_substr($body['reason'], 0, 500) : null;

            return $this->idempotency->run('orders.cancel', $key, ['order_id' => $orderId, 'customer' => $customer->ref, 'reason' => $reason], function () use ($orderId, $customer, $reason) {
                $result = $this->capabilities->adapter('order_cancellation', OrderCancellationAdapterInterface::class)
                    ->cancel($orderId, $customer, $reason);

                return [200, ['data' => $result->toArray()]];
            });
        });
    }

    /** What happened to a write, for the SaaS to reconcile after a lost response. */
    public function idempotency(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);
            $operation = $body['operation'] ?? null;

            if (! in_array($operation, ['orders.create', 'orders.cancel'], true)) {
                throw ContractException::field('operation', 'orders.create or orders.cancel');
            }

            return $this->idempotency->lookup($operation, $this->idempotencyKey($body));
        });
    }

    private function orders(): OrderAdapterInterface
    {
        return $this->capabilities->adapter('orders', OrderAdapterInterface::class);
    }
}
