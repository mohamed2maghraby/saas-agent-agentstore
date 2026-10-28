<?php

namespace SaasAgent\AgentStore\Store\Idempotency;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\Models\IdempotencyRecord;
use SaasAgent\AgentStore\Store\StoreRefusal;

/**
 * Runs a write at most once per (operation, idempotency key).
 *
 * - Same key, same request, already done → the stored response, marked replayed.
 * - Same key, different request → 409 idempotency_conflict; nothing runs.
 * - Same key, still running → 409 in_progress (retryable).
 *
 * Transactional mode (the default) runs the write and its record in one
 * database transaction. If the adapter writes through the same connection, a
 * crash leaves either both or neither, so retrying after a failure is always
 * safe. Without a transaction a crash can leave a `processing` record behind:
 * the SaaS then reconciles through /idempotency/lookup instead of retrying.
 */
final class IdempotentWrite
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $request  the validated request, hashed to detect reuse
     * @param  Closure(): array{0: int, 1: array<string, mixed>}  $write  returns [status, body]
     */
    public function run(string $operation, string $key, array $request, Closure $write): JsonResponse
    {
        // Round-trip through JSON so objects become arrays and sort like them.
        $request = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true);
        $hash = hash('sha256', json_encode(self::sorted($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            return config('agentstore.idempotency.transactional', true)
                ? $this->db->transaction(fn () => $this->attempt($operation, $key, $hash, $write))
                : $this->attempt($operation, $key, $hash, $write);
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key raced to insert; this one lost.
            return ApiError::response(ErrorCode::InProgress);
        }
    }

    private function attempt(string $operation, string $key, string $hash, Closure $write): JsonResponse
    {
        $existing = IdempotencyRecord::query()
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $this->answerFor($existing, $hash);
        }

        $record = new IdempotencyRecord;
        $record->forceFill([
            'operation' => $operation,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'status' => IdempotencyRecord::PROCESSING,
        ])->save();

        try {
            [$status, $body] = $write();
        } catch (StoreRefusal $refusal) {
            // A business "no" is an outcome like any other: recorded and replayed.
            [$status, $body] = [$refusal->errorCode->status(), ApiError::body($refusal->errorCode, $refusal->getMessage(), $refusal->details)];
        }

        $record->forceFill([
            'status' => IdempotencyRecord::COMPLETED,
            'response_status' => $status,
            'response_body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'completed_at' => now(),
        ])->save();

        return new JsonResponse($body, $status);
    }

    private function answerFor(IdempotencyRecord $record, string $hash): JsonResponse
    {
        if (! hash_equals($record->request_hash, $hash)) {
            return ApiError::response(ErrorCode::IdempotencyConflict);
        }

        if ($record->status !== IdempotencyRecord::COMPLETED) {
            return ApiError::response(ErrorCode::InProgress);
        }

        $response = new JsonResponse(json_decode($record->response_body, true), $record->response_status);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    /** @return array{status: string, response_status?: int, response?: mixed} */
    public function lookup(string $operation, string $key): array
    {
        $record = IdempotencyRecord::query()->where('operation', $operation)->where('idempotency_key', $key)->first();

        if (! $record) {
            return ['status' => 'not_found'];
        }

        if ($record->status !== IdempotencyRecord::COMPLETED) {
            return ['status' => 'processing'];
        }

        return [
            'status' => 'completed',
            'response_status' => $record->response_status,
            'response' => json_decode($record->response_body, true),
        ];
    }

    /** Key order must not change the hash. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'sorted'], $value);
    }
}
