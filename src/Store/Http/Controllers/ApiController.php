<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;
use SaasAgent\AgentStore\Store\CustomerToken;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\StoreRefusal;
use Throwable;

/**
 * Shared plumbing for the contract endpoints.
 *
 * respond() is the only place exceptions become responses: contract violations
 * are 422 with the field named, a store refusal keeps its code, and anything
 * else is logged in the store and answered with a generic store_error — the
 * exception's text never reaches the SaaS.
 */
abstract class ApiController
{
    /** @param Closure(): (JsonResponse|array<string, mixed>) $action */
    protected function respond(Closure $action): JsonResponse
    {
        try {
            $result = $action();

            return $result instanceof JsonResponse ? $result : new JsonResponse(['data' => $result]);
        } catch (ContractException $e) {
            return ApiError::response(ErrorCode::ValidationFailed, $e->getMessage());
        } catch (StoreRefusal $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->details);
        } catch (Throwable $e) {
            report($e);

            return ApiError::response(ErrorCode::StoreError);
        }
    }

    /** @return array<string, mixed> */
    protected function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        if (! is_array($data)) {
            throw new ContractException('The request body must be a JSON object.');
        }

        return $data;
    }

    /**
     * A list of ids from the body, bounded.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    protected function ids(array $body, string $key = 'variant_ids', int $max = 100): array
    {
        $ids = $body[$key] ?? null;

        if (! is_array($ids) || $ids === [] || count($ids) > $max) {
            throw ContractException::field($key, "a list of 1 to {$max} ids");
        }

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '' || strlen($id) > 191) {
                throw ContractException::field($key, 'a list of string ids');
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The customer a token stands for, or a refusal that asks the SaaS to verify again.
     *
     * @param  array<string, mixed>  $body
     */
    protected function customer(Request $request, array $body): VerifiedCustomer
    {
        $token = $body['customer_token'] ?? null;
        $customer = is_string($token)
            ? app(CustomerToken::class)->open($token, (string) $request->attributes->get('agentstore.key_id'))
            : null;

        return $customer ?? throw new StoreRefusal(ErrorCode::CustomerVerificationRequired, 'The customer must be verified again.');
    }

    /** @param array<string, mixed> $body */
    protected function idempotencyKey(array $body): string
    {
        $key = $body['idempotency_key'] ?? null;

        if (! is_string($key) || ! preg_match('/\A[A-Za-z0-9\-_.:]{8,191}\z/', $key)) {
            throw ContractException::field('idempotency_key', 'a string of 8 to 191 safe characters');
        }

        return $key;
    }
}
