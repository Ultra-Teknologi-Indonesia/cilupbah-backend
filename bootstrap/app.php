<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Exceptions\UserFacingException;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\PaymentExceedsInvoiceException;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Modules\Outbound\Exceptions\OutboundValidationException;

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
                $responder = new class { use \App\Traits\ApiResponse; };

                if ($e instanceof UserFacingException) {
                    return $responder->errorResponse(
                        $e->getMessage(),
                        $e->getStatus(),
                        $e->getErrors(),
                        $e->getTitle(),
                    );
                }

                if ($e instanceof DuplicateOrderException
                    || $e instanceof InvalidReturnStateException) {
                    return $responder->errorResponse($e->getMessage(), 409, null, 'Konflik data');
                }

                if ($e instanceof InsufficientStockException
                    || $e instanceof InvalidStatusTransitionException
                    || $e instanceof LocationNotConfiguredException
                    || $e instanceof PaymentExceedsInvoiceException
                    || $e instanceof ProductNotMappableException
                    || $e instanceof CannotDeleteActiveOrderException
                    || $e instanceof OutboundValidationException) {
                    return $responder->errorResponse($e->getMessage(), 422, null, 'Aksi tidak dapat diproses');
                }

                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $errors = $e->errors();
                    $firstField = array_key_first($errors);
                    $firstMessage = $firstField && !empty($errors[$firstField])
                        ? (is_array($errors[$firstField]) ? $errors[$firstField][0] : $errors[$firstField])
                        : 'Mohon periksa kembali data yang Anda masukkan.';

                    return $responder->errorResponse($firstMessage, 422, $errors, 'Data belum lengkap');
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return $responder->errorResponse(
                        'Silakan masuk kembali untuk melanjutkan.',
                        401,
                        null,
                        'Sesi berakhir',
                    );
                }

                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return $responder->errorResponse(
                        $e->getMessage() ?: 'Anda tidak memiliki izin untuk melakukan aksi ini.',
                        403,
                        null,
                        'Akses ditolak',
                    );
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return $responder->errorResponse(
                        'Data yang Anda cari tidak dapat ditemukan.',
                        404,
                        null,
                        'Data tidak ditemukan',
                    );
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $status = $e->getStatusCode();
                    $title = match (true) {
                        $status === 403 => 'Akses ditolak',
                        $status === 404 => 'Data tidak ditemukan',
                        $status === 409 => 'Konflik data',
                        $status === 422 => 'Aksi tidak dapat diproses',
                        $status === 429 => 'Terlalu banyak permintaan',
                        default => 'Terjadi kesalahan',
                    };

                    return $responder->errorResponse(
                        $e->getMessage() ?: 'Permintaan tidak dapat diproses.',
                        $status,
                        null,
                        $title,
                    );
                }

                $exposeDetail = ! app()->environment('production');

                return $responder->errorResponse(
                    'Silakan laporkan ke admin/developer terkait masalah ini.',
                    500,
                    $exposeDetail ? [
                        'error' => $e->getMessage(),
                        'exception' => class_basename($e),
                        'file' => $e->getFile() . ':' . $e->getLine(),
                    ] : null,
                    'Terjadi kesalahan server',
                );
            }
        });
    })->create();
