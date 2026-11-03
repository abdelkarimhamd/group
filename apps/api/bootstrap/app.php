<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The API error body: {"message": localized text, "code": stable reason}.
 */
$apiError = fn (string $code, int $status, array $extra = [], array $headers = []) => response()->json(
    ['message' => __('messages.errors.'.$code), 'code' => $code] + $extra,
    $status,
    $headers,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Campaign/Console',
        __DIR__.'/../app/Modules/Fulfillment/Console',
        __DIR__.'/../app/Modules/Identity/Console',
        __DIR__.'/../app/Modules/Notifications/Console',
        __DIR__.'/../app/Modules/Payments/Console',
        __DIR__.'/../app/Modules/Supplier/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies must run first, so the request ID middleware records the
        // client's IP rather than the load balancer's.
        $middleware->prepend([TrustProxies::class, AssignRequestId::class]);
        // Language first in the API group, so errors from auth and rate limits
        // (route middleware that Laravel sorts ahead of the rest) are localized too.
        $middleware->api(prepend: [SetLocaleFromRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Framework exceptions in the API's error shape. App\Exceptions\ApiException
        // renders itself.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $apiError('unauthenticated', 401) : null);

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $request->is('api/*')
            ? $apiError('forbidden', 403) : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? $apiError('not_found', 404) : null);

        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? $apiError('validation_failed', $e->status, ['errors' => $e->errors()]) : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? $apiError('too_many_requests', 429, [], $e->getHeaders()) : null);
    })->create();
