<?php

namespace SaasAgent\AgentStore\Store\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\Models\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits only requests signed by the paired SaaS. See ADR 0010.
 *
 * Every rejection looks the same from outside — which check failed is not
 * disclosed. The nonce is recorded only after the signature checks out, so
 * unsigned traffic cannot fill the replay cache.
 */
final class VerifySignature
{
    public function __construct(private readonly Cache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->headers->get(Signature::KEY_HEADER, '');
        $timestamp = (string) $request->headers->get(Signature::TIMESTAMP_HEADER, '');
        $nonce = (string) $request->headers->get(Signature::NONCE_HEADER, '');
        $signature = (string) $request->headers->get(Signature::SIGNATURE_HEADER, '');
        $tolerance = (int) config('agentstore.signature_tolerance', 300);

        if ($keyId === '' || strlen($keyId) > 40 || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > $tolerance || ! Signature::validNonce($nonce)) {
            return ApiError::response(ErrorCode::Unauthenticated);
        }

        $connection = Connection::findForKey($keyId);
        $secret = $connection?->requestSecretFor($keyId);

        if ($secret === null) {
            return ApiError::response(ErrorCode::Unauthenticated);
        }

        $canonical = Signature::canonical(
            $request->getMethod(),
            (string) parse_url($request->getRequestUri(), PHP_URL_PATH),
            $request->query->all(),
            (int) $timestamp,
            $nonce,
            $request->getContent(),
        );

        if (! Signature::matches($secret, $canonical, $signature)) {
            return ApiError::response(ErrorCode::Unauthenticated);
        }

        // add() is atomic: only the first request with this nonce gets through.
        if (! $this->cache->add("agentstore:nonce:{$keyId}:{$nonce}", 1, $tolerance * 2)) {
            return ApiError::response(ErrorCode::Unauthenticated);
        }

        $request->attributes->set('agentstore.connection', $connection);
        $request->attributes->set('agentstore.key_id', $keyId);

        return $next($request);
    }
}
