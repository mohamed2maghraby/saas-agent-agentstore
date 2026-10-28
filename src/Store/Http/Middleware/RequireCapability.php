<?php

namespace SaasAgent\AgentStore\Store\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Store\Capabilities;
use SaasAgent\AgentStore\Store\Http\ApiError;
use Symfony\Component\HttpFoundation\Response;

/**
 * `agentstore.capability:orders.create` — answers 501 unsupported_capability for
 * anything the store did not configure or has switched off. Checked per call,
 * so the runtime policy applies immediately.
 */
final class RequireCapability
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (! $this->capabilities->allows(Capability::from($capability))) {
            return ApiError::response(ErrorCode::UnsupportedCapability);
        }

        return $next($request);
    }
}
