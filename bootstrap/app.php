<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*');
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation error',
                        'errors' => $e->errors()
                    ], 422);
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

                return response()->json([
                    'status' => 'error',
                    'message' => 'Internal Server Error',
                    'error' => env('APP_DEBUG') ? $e->getMessage() : null,
                ], 500);
            }
        });
    })->create();
