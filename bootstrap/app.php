<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\PaymentExceedsInvoiceException;
use Modules\Sales\Exceptions\ProductNotMappableException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'dev.only' => \App\Http\Middleware\DevOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*');
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof DuplicateOrderException
                    || $e instanceof InvalidReturnStateException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ], 409);
                }

                if ($e instanceof InsufficientStockException
                    || $e instanceof InvalidStatusTransitionException
                    || $e instanceof LocationNotConfiguredException
                    || $e instanceof PaymentExceedsInvoiceException
                    || $e instanceof ProductNotMappableException
                    || $e instanceof CannotDeleteActiveOrderException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ], 422);
                }

                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation error',
                        'errors' => $e->errors()
                    ], 422);
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthenticated',
                        'data' => null
                    ], 401);
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Data tidak ditemukan',
                        'data' => null
                    ], 404);
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $e->getMessage() ?: 'Terjadi kesalahan',
                        'data' => null
                    ], $e->getStatusCode());
                }

                $exposeDetail = ! app()->environment('production');

                return response()->json([
                    'status' => 'error',
                    'message' => 'Internal Server Error',
                    'error' => $exposeDetail ? $e->getMessage() : null,
                    'exception' => $exposeDetail ? class_basename($e) : null,
                    'file' => $exposeDetail ? $e->getFile() . ':' . $e->getLine() : null,
                ], 500);
            }
        });
    })->create();
