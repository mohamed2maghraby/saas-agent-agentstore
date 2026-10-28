<?php

namespace SaasAgent\AgentStore\Store\Pairing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SaasAgent\AgentStore\Contract\ContractVersion;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\Models\Connection;
use SaasAgent\AgentStore\Store\Models\Pairing;

/**
 * The last step of pairing, called by the SaaS (ADR 0009).
 *
 * The caller proves it is the SaaS this store approved by presenting the
 * approval token — which went from this store straight to the configured SaaS
 * and nowhere else. Only then are the credentials it brings stored. The reply
 * carries a proof computed with the new request secret.
 */
final class VerifyPairingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        if (! is_array($data)) {
            return ApiError::response(ErrorCode::ValidationFailed);
        }

        $pairingId = (string) ($data['pairing_id'] ?? '');
        $token = (string) ($data['approval_token'] ?? '');

        try {
            $credentials = new Credentials(
                (string) ($data['key_id'] ?? ''),
                (string) ($data['request_secret'] ?? ''),
                (string) ($data['webhook_secret'] ?? ''),
            );
        } catch (\InvalidArgumentException) {
            return ApiError::response(ErrorCode::ValidationFailed);
        }

        return DB::transaction(function () use ($pairingId, $token, $credentials) {
            $pairing = Pairing::query()->where('pairing_id', $pairingId)->lockForUpdate()->first();

            // Unknown, expired, already used or wrong token: one answer for all of them.
            if (! $pairing || ! $pairing->isOpen() || ! hash_equals($pairing->token_hash, hash('sha256', $token))) {
                return ApiError::response(ErrorCode::Forbidden);
            }

            Connection::establish($credentials, rtrim((string) config('agentstore.saas_url'), '/'), $pairing->tenant_label, $pairing->approved_by);
            $pairing->forceFill(['completed_at' => now()])->save();

            return new JsonResponse(['data' => [
                'proof' => Signature::proof($credentials->requestSecret, $pairingId, $credentials->keyId),
                'contract_version' => ContractVersion::CURRENT,
                'package_version' => ContractVersion::PACKAGE,
                'capabilities' => $pairing->capabilities,
            ]]);
        });
    }
}
