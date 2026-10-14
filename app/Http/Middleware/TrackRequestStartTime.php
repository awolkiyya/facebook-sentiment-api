<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackRequestStartTime
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $request->attributes->set(
            'request_started_at',
            microtime(true)
        );

        return $next($request);
    }
}