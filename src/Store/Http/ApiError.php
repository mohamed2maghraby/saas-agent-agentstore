<?php

namespace SaasAgent\AgentStore\Store\Http;

use Illuminate\Http\JsonResponse;
use SaasAgent\AgentStore\Contract\ErrorCode;

/**
 * The one error shape the contract uses:
 *   {"error": {"code": "...", "message": "...", "retryable": bool, "details": {...}}}
 *
 * Messages are generic on purpose. A store's exception text — a SQL error, a
 * file path — never leaves the store.
 */
final class ApiError
{
    private const MESSAGES = [
        'unauthenticated' => 'The request could not be authenticated.',
        'forbidden' => 'This request is not allowed.',
        'not_found' => 'Not found.',
        'validation_failed' => 'The request is invalid.',
        'unsupported_capability' => 'This store does not support that operation.',
        'idempotency_conflict' => 'This idempotency key was already used for a different request.',
        'in_progress' => 'A request with this idempotency key is still being processed.',
        'not_cancellable' => 'This order cannot be cancelled.',
        'out_of_stock' => 'One or more items are out of stock.',
        'customer_verification_required' => 'The customer must be verified again.',
        'rate_limited' => 'Too many requests.',
        'store_error' => 'The store could not complete the request.',
        'unavailable' => 'The store is temporarily unavailable.',
    ];

    /** @param array<string, mixed> $details */
    public static function response(ErrorCode $code, ?string $message = null, array $details = []): JsonResponse
    {
        return new JsonResponse(self::body($code, $message, $details), $code->status());
    }

    /** @param array<string, mixed> $details */
    public static function body(ErrorCode $code, ?string $message = null, array $details = []): array
    {
        return ['error' => array_filter([
            'code' => $code->value,
            'message' => $message ?? self::MESSAGES[$code->value],
            'retryable' => $code->retryable(),
            'details' => $details ?: null,
        ], fn ($value) => $value !== null)];
    }
}
