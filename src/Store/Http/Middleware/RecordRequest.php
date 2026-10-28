<?php

namespace SaasAgent\AgentStore\Store\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Store\Models\RequestRecord;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records each call from the SaaS: which operation, how it ended, how long it
 * took, and the SaaS's correlation id for tracing. Never the body.
 */
final class RecordRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        try {
            $error = null;
            if ($response->getStatusCode() >= 400 && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
                $error = json_decode((string) $response->getContent(), true)['error']['code'] ?? null;
            }

            $correlation = (string) $request->headers->get('X-Request-Id', '');

            $record = new RequestRecord;
            $record->forceFill([
                'operation' => mb_substr((string) ($request->route()?->getName() ?? $request->path()), 0, 60),
                'method' => $request->getMethod(),
                'status' => $response->getStatusCode(),
                'error_code' => is_string($error) ? mb_substr($error, 0, 40) : null,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'correlation_id' => preg_match('/\A[A-Za-z0-9\-_.:]{8,64}\z/', $correlation) ? $correlation : null,
                'idempotency_key' => mb_substr((string) ($request->json('idempotency_key') ?? ''), 0, 191) ?: null,
                'created_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // Losing a log row must never fail the call it describes.
            report($e);
        }

        return $response;
    }
}
