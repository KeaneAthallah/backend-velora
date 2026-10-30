<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This backend has no login route: a guest is never redirected to an
        // HTML page, so unauthenticated requests reach the JSON 401 envelope
        // below whatever Accept header they send.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'auth.sanctum' => EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $e->errors(),
                        'code' => 'VALIDATION_ERROR',
                        'request_id' => (string) Str::uuid(),
                    ], 422);
                }
                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthenticated',
                        'errors' => [],
                        'code' => 'UNAUTHENTICATED',
                        'request_id' => (string) Str::uuid(),
                    ], 401);
                }
                if ($e instanceof HttpException) {
                    $status = $e->getStatusCode();

                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Request failed',
                        'errors' => [],
                        'code' => 'HTTP_ERROR',
                        'request_id' => (string) Str::uuid(),
                    ], $status);
                }
                $status = 500;

                return response()->json([
                    'success' => false,
                    'message' => 'Server error',
                    'errors' => [],
                    'code' => 'SERVER_ERROR',
                    'request_id' => (string) Str::uuid(),
                ], $status);
            }
        });
    })->create();
