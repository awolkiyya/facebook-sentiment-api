<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * AssignRequestId
 *
 * Generates (or reuses, if the client/load balancer already sent one)
 * a single request_id at the very start of the request lifecycle, and:
 *
 *  1. Stores it on the request so ApiResponse::requestId() picks it up.
 *  2. Pushes it into the log context, so every log line written during
 *     this request — including ones from queued jobs dispatched sync,
 *     3rd-party package logs, etc. — is automatically tagged with it.
 *  3. Echoes it back as X-Request-Id so mobile/web clients can show it
 *     in a "contact support" screen or bug report.
 *
 * This is what makes request_id useful for correlating a single user
 * action across web logs, mobile crash reports, and backend logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}