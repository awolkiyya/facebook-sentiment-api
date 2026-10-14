<?php

// bootstrap/app.php

use App\Modules\Auth\Exceptions\AccountLockedException;
use App\Services\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(
    basePath: dirname(__DIR__)
)

    /*
    |--------------------------------------------------------------------------
    | Application Routing
    |--------------------------------------------------------------------------
    */

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | API Request Tracking
        |--------------------------------------------------------------------------
        |
        | Assign a unique correlation ID and start a high-resolution
        | request timer for every API request.
        |
        */

        $middleware->api(
            prepend: [
                \App\Http\Middleware\AssignRequestId::class,
                \App\Http\Middleware\TrackRequestStartTime::class,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Sanctum Stateful API
        |--------------------------------------------------------------------------
        |
        | Enables Laravel Sanctum's stateful authentication for SPA
        | requests using cookies and sessions.
        |
        */

        $middleware->statefulApi();


        /*
        |--------------------------------------------------------------------------
        | Middleware Aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'log.auth' => \App\Http\Middleware\LogAuthenticationRequest::class,
        ]);
    })

    /*
    |--------------------------------------------------------------------------
    | Exception Handling
    |--------------------------------------------------------------------------
    */

    ->withExceptions(function (Exceptions $exceptions) {

        /*
        |--------------------------------------------------------------------------
        | API / JSON Request Detection
        |--------------------------------------------------------------------------
        |
        | Only API/JSON requests receive the application's standardized
        | JSON response envelope.
        |
        | Normal web requests continue using Laravel's default HTML
        | exception rendering.
        |
        */

        $isApiRequest = static function (Request $request): bool {
            return $request->is('api/*')
                || $request->expectsJson();
        };

        /*
        |--------------------------------------------------------------------------
        | Validation Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 422
        |
        */

        $exceptions->render(
            function (
                ValidationException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::validation(
                    $exception->errors(),
                    'The given data was invalid.'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Account Locked
        |--------------------------------------------------------------------------
        |
        | HTTP 423
        |
        | This is an expected security/business exception.
        |
        */

        $exceptions->render(
            function (
                AccountLockedException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::error(
                    $exception->getMessage(),
                    [
                        'remaining_seconds' => $exception->remainingSeconds,
                        'locked_until' => $exception->lockedUntil
                            ?->toIso8601String(),
                    ],
                    423,
                    'ACCOUNT_LOCKED'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Authentication Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 401
        |
        */

        $exceptions->render(
            function (
                AuthenticationException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::unauthorized(
                    'Authentication is required.'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Authorization Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 403
        |
        */

        $exceptions->render(
            function (
                AuthorizationException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::forbidden(
                    $exception->getMessage()
                        ?: 'You do not have permission to perform this action.'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Model Not Found
        |--------------------------------------------------------------------------
        |
        | HTTP 404
        |
        */

        $exceptions->render(
            function (
                ModelNotFoundException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                $model = class_basename(
                    $exception->getModel()
                );

                return ApiResponse::notFound(
                    "{$model} not found."
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Route Not Found
        |--------------------------------------------------------------------------
        |
        | HTTP 404
        |
        */

        $exceptions->render(
            function (
                NotFoundHttpException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::notFound(
                    'The requested endpoint does not exist.'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Method Not Allowed
        |--------------------------------------------------------------------------
        |
        | HTTP 405
        |
        */

        $exceptions->render(
            function (
                MethodNotAllowedHttpException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                return ApiResponse::error(
                    'The HTTP method is not supported for this endpoint.',
                    null,
                    405,
                    'METHOD_NOT_ALLOWED'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Too Many Requests
        |--------------------------------------------------------------------------
        |
        | HTTP 429
        |
        */

        $exceptions->render(
            function (
                TooManyRequestsHttpException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                $retryAfter = $exception
                    ->getHeaders()['Retry-After']
                    ?? null;

                return ApiResponse::tooManyRequests(
                    'Too many requests. Please try again later.',
                    $retryAfter
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Database Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 500
        |
        | Never expose:
        |
        | - SQL queries
        | - database credentials
        | - table names
        | - column names
        | - PostgreSQL/MySQL errors
        | - stack traces
        | - internal application details
        |
        */

        $exceptions->render(
            function (
                QueryException $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                report($exception);

                return ApiResponse::serverError(
                    'A database error occurred. Please try again later.'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Generic HTTP Exception
        |--------------------------------------------------------------------------
        |
        | Handles other Symfony/Laravel HTTP exceptions.
        |
        | Do NOT expose $exception->getMessage() directly because some
        | framework/application exceptions may contain internal details.
        |
        */

        $exceptions->render(
            function (
                HttpExceptionInterface $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                $status = $exception->getStatusCode();

                /*
                |--------------------------------------------------------------------------
                | Known HTTP Status Messages
                |--------------------------------------------------------------------------
                */

                $message = match ($status) {
                    400 => 'The request could not be understood.',
                    401 => 'Authentication is required.',
                    403 => 'You do not have permission to perform this action.',
                    404 => 'The requested resource was not found.',
                    405 => 'The HTTP method is not supported.',
                    406 => 'The requested response format is not supported.',
                    408 => 'The request timed out.',
                    409 => 'The request conflicts with the current state.',
                    410 => 'The requested resource is no longer available.',
                    415 => 'The request media type is not supported.',
                    422 => 'The request could not be processed.',
                    429 => 'Too many requests. Please try again later.',
                    500 => 'An internal server error occurred.',
                    502 => 'The service is temporarily unavailable.',
                    503 => 'The service is temporarily unavailable.',
                    504 => 'The service timed out.',
                    default => 'The request could not be completed.',
                };

                return ApiResponse::error(
                    $message,
                    null,
                    $status,
                    'HTTP_ERROR'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Final Exception Safety Net
        |--------------------------------------------------------------------------
        |
        | Any unexpected exception that reaches this point is considered
        | an internal server error.
        |
        | The complete exception is reported internally.
        |
        | The client receives ONLY a safe generic response.
        |
        */

        $exceptions->render(
            function (
                \Throwable $exception,
                Request $request
            ) use ($isApiRequest) {

                if (! $isApiRequest($request)) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Internal Logging
                |--------------------------------------------------------------------------
                */

                report($exception);

                /*
                |--------------------------------------------------------------------------
                | Safe API Response
                |--------------------------------------------------------------------------
                */

                return ApiResponse::serverError(
                    'Something went wrong on our end. Please try again later.'
                );
            }
        );
    })

    ->create();