<?php

namespace SaasAgent\AgentStore\Store;

use RuntimeException;
use SaasAgent\AgentStore\Contract\ErrorCode;

/**
 * A business "no" from an adapter: the item sold out, the coupon does not
 * apply, the address is outside delivery. It is an answer, not a failure — it is
 * recorded against the idempotency key and returned as is on a retry.
 */
final class StoreRefusal extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function outOfStock(string $message = 'One or more items are out of stock.', array $details = []): self
    {
        return new self(ErrorCode::OutOfStock, $message, $details);
    }

    public static function invalid(string $message, array $details = []): self
    {
        return new self(ErrorCode::ValidationFailed, $message, $details);
    }
}
