<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Every error either side returns, with whether retrying could help.
 *
 * Body: {"error": {"code": "...", "message": "...", "retryable": bool}}
 */
enum ErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case UnsupportedCapability = 'unsupported_capability';
    case IdempotencyConflict = 'idempotency_conflict';
    case InProgress = 'in_progress';
    case NotCancellable = 'not_cancellable';
    case OutOfStock = 'out_of_stock';
    case CustomerVerificationRequired = 'customer_verification_required';
    case RateLimited = 'rate_limited';
    case StoreError = 'store_error';
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated, self::CustomerVerificationRequired => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::IdempotencyConflict, self::InProgress => 409,
            self::ValidationFailed, self::NotCancellable, self::OutOfStock => 422,
            self::RateLimited => 429,
            self::UnsupportedCapability => 501,
            self::StoreError => 500,
            self::Unavailable => 503,
        };
    }

    public function retryable(): bool
    {
        return in_array($this, [self::InProgress, self::RateLimited, self::Unavailable], true);
    }
}
