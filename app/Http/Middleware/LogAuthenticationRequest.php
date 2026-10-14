<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogAuthenticationRequest
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $sessionCookieName = config('session.cookie');

        /*
        |--------------------------------------------------------------------------
        | BEFORE SANCTUM
        |--------------------------------------------------------------------------
        */

        Log::info('AUTH DEBUG: BEFORE SANCTUM', [

            /*
            |--------------------------------------------------------------------------
            | Request
            |--------------------------------------------------------------------------
            */

            'method' => $request->method(),

            'url' => $request->fullUrl(),

            'path' => $request->path(),

            'host' => $request->getHost(),

            'ip' => $request->ip(),

            'origin' => $request->header('Origin'),

            'referer' => $request->header('Referer'),

            'user_agent' => $request->userAgent(),


            /*
            |--------------------------------------------------------------------------
            | Session
            |--------------------------------------------------------------------------
            */

            'session_driver' => config('session.driver'),

            'session_cookie_name' => $sessionCookieName,

            'session_cookie_present' =>
                $request->hasCookie($sessionCookieName),

            'session_available' =>
                $request->hasSession(),

            'session_id' =>
                $request->hasSession()
                    ? substr($request->session()->getId(), 0, 8) . '...'
                    : null,


            /*
            |--------------------------------------------------------------------------
            | XSRF
            |--------------------------------------------------------------------------
            */

            'xsrf_cookie_present' =>
                $request->hasCookie('XSRF-TOKEN'),

            'xsrf_header_present' =>
                $request->hasHeader('X-XSRF-TOKEN'),


            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            |
            | We NEVER log the actual bearer token.
            |
            */

            'authorization_header_present' =>
                $request->hasHeader('Authorization'),

            'bearer_token_present' =>
                $request->bearerToken() !== null,

            'bearer_token_length' =>
                $request->bearerToken()
                    ? strlen($request->bearerToken())
                    : 0,


            /*
            |--------------------------------------------------------------------------
            | Sanctum configuration
            |--------------------------------------------------------------------------
            */

            'sanctum_stateful_domains' =>
                config('sanctum.stateful'),

            'session_domain' =>
                config('session.domain'),

            'session_same_site' =>
                config('session.same_site'),

            'session_secure' =>
                config('session.secure'),

            'session_http_only' =>
                config('session.http_only'),


            /*
            |--------------------------------------------------------------------------
            | Authentication BEFORE auth:sanctum
            |--------------------------------------------------------------------------
            */

            'sanctum_check_before' =>
                auth('sanctum')->check(),

            'sanctum_user_before' =>
                auth('sanctum')->id(),

            'default_auth_check_before' =>
                auth()->check(),

            'default_auth_user_before' =>
                auth()->id(),

            'request_user_before' =>
                $request->user()?->id,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Continue
        |--------------------------------------------------------------------------
        */

        $response = $next($request);


        /*
        |--------------------------------------------------------------------------
        | AFTER SANCTUM
        |--------------------------------------------------------------------------
        */

        Log::info('AUTH DEBUG: AFTER SANCTUM', [

            'method' => $request->method(),

            'path' => $request->path(),

            'status' => $response->getStatusCode(),


            /*
            |--------------------------------------------------------------------------
            | Authentication result
            |--------------------------------------------------------------------------
            */

            'sanctum_check_after' =>
                auth('sanctum')->check(),

            'sanctum_user_after' =>
                auth('sanctum')->id(),

            'default_auth_check_after' =>
                auth()->check(),

            'default_auth_user_after' =>
                auth()->id(),

            'request_user_after' =>
                $request->user()?->id,


            /*
            |--------------------------------------------------------------------------
            | Session
            |--------------------------------------------------------------------------
            */

            'session_id_after' =>
                $request->hasSession()
                    ? substr($request->session()->getId(), 0, 8) . '...'
                    : null,
        ]);


        return $response;
    }
}