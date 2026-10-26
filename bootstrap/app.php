<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'auth.sanctum' => \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $e->errors(),
                        'code' => 'VALIDATION_ERROR',
                        'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    ], 422);
                }
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthenticated',
                        'errors' => [],
                        'code' => 'UNAUTHENTICATED',
                        'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    ], 401);
                }
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $status = $e->getStatusCode();
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Request failed',
                        'errors' => [],
                        'code' => 'HTTP_ERROR',
                        'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    ], $status);
                }
                $status = 500;
                return response()->json([
                    'success' => false,
                    'message' => 'Server error',
                    'errors' => [],
                    'code' => 'SERVER_ERROR',
                    'request_id' => (string) \Illuminate\Support\Str::uuid(),
                ], $status);
            }
        });
    })->create();
