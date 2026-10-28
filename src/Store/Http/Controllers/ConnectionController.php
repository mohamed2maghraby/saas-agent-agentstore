<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\Connection;

/** Rotation and revocation, both requested by the SaaS over a signed call. */
final class ConnectionController extends ApiController
{
    public function rotate(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $body = $this->body($request);

            try {
                $credentials = new Credentials(
                    (string) ($body['key_id'] ?? ''),
                    (string) ($body['request_secret'] ?? ''),
                    (string) ($body['webhook_secret'] ?? ''),
                );
            } catch (\InvalidArgumentException) {
                throw new ContractException('The new credentials are malformed.');
            }

            /** @var Connection $connection */
            $connection = $request->attributes->get('agentstore.connection');
            $connection->rotate($credentials);

            // Signed with the new secret: proof the store now holds it.
            return ['proof' => Signature::proof($credentials->requestSecret, 'rotate', $credentials->keyId)];
        });
    }

    public function revoke(Request $request): JsonResponse
    {
        return $this->respond(function () {
            Connection::query()->delete();

            return ['revoked' => true];
        });
    }
}
