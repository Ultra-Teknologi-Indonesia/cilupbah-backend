<?php

use App\Exceptions\UserFacingException;
use App\Http\Middleware\DevOnly;
use App\Http\Middleware\NormalizePaginationParameters;
use App\Http\Middleware\RejectNonAccessToken;
use App\Http\Middleware\ResilientThrottleRequests;
use App\Http\Middleware\ResolveClientChannel;
use App\Support\DatabaseAvailability;
use App\Support\ErrorReporter;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\PaymentExceedsInvoiceException;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $trustedProxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(at: $trustedProxies
            ? array_map('trim', explode(',', $trustedProxies))
            : [
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '127.0.0.1',
                'fc00::/7',
                '::1',
            ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'dev.only' => DevOnly::class,
            'client.channel' => ResolveClientChannel::class,

            'throttle' => ResilientThrottleRequests::class,
        ]);

        $middleware->api(
            prepend: [
                ResolveClientChannel::class,
                RejectNonAccessToken::class,
            ],
            append: [
                NormalizePaginationParameters::class,
                'throttle:api',
            ],
        );

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*')
                ? null
                : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*');
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $responder = new class
                {
                    use ApiResponse;
                };

                if (DatabaseAvailability::isTransient($e)) {
                    Log::warning('API unavailable because database connectivity is temporarily limited.', [
                        'exception' => class_basename($e),
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ]);

                    return $responder->errorResponse(
                        'Server sedang padat. Silakan coba lagi beberapa saat.',
                        503,
                        null,
                        'Layanan sementara tidak tersedia',
                        'DATABASE_CAPACITY_TEMPORARILY_UNAVAILABLE',
                    )->header('Retry-After', (string) config('app.database_unavailable_retry_after', 10));
                }

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

                if ($e instanceof ValidationException) {
                    $errors = $e->errors();
                    $firstField = array_key_first($errors);
                    $firstMessage = $firstField && ! empty($errors[$firstField])
                        ? (is_array($errors[$firstField]) ? $errors[$firstField][0] : $errors[$firstField])
                        : 'Mohon periksa kembali data yang Anda masukkan.';

                    ErrorReporter::captureThrottled(
                        $e,
                        'validation:'.($request->route()?->getName() ?: $request->path()).':'.(string) $firstField,
                        ['http_status' => 422, 'error_kind' => 'validation'],
                    );

                    return $responder->errorResponse($firstMessage, 422, $errors, 'Data belum lengkap');
                }

                if ($e instanceof AuthenticationException) {
                    return $responder->errorResponse(
                        'Silakan masuk kembali untuk melanjutkan.',
                        401,
                        null,
                        'Sesi berakhir',
                    );
                }

                if ($e instanceof AuthorizationException) {
                    return $responder->errorResponse(
                        $e->getMessage() ?: 'Anda tidak memiliki izin untuk melakukan aksi ini.',
                        403,
                        null,
                        'Akses ditolak',
                    );
                }

                if ($e instanceof NotFoundHttpException ||
                    $e instanceof ModelNotFoundException) {
                    return $responder->errorResponse(
                        'Data yang Anda cari tidak dapat ditemukan',
                        404,
                        null,
                        'Data tidak ditemukan',
                    );
                }

                if ($e instanceof HttpException) {
                    $status = $e->getStatusCode();

                    if ($status === 503) {
                        return $responder->errorResponse(
                            'Layanan sedang tidak tersedia. Silakan coba lagi beberapa saat.',
                            503,
                            null,
                            'Layanan sementara tidak tersedia',
                            'SERVICE_TEMPORARILY_UNAVAILABLE',
                        )->header('Retry-After', (string) config('app.database_unavailable_retry_after', 10));
                    }

                    if ($status === 422) {
                        ErrorReporter::captureThrottled(
                            $e,
                            'http422:'.$request->path(),
                            ['http_status' => 422, 'error_kind' => 'http'],
                        );
                    }

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

                Log::error($e->getMessage(), [
                    'exception' => $e,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]);

                $exposeDetail = ! app()->environment('production');

                return $responder->errorResponse(
                    'Silakan laporkan ke admin/developer terkait masalah ini.',
                    500,
                    $exposeDetail ? [
                        'error' => $e->getMessage(),
                        'exception' => class_basename($e),
                        'file' => $e->getFile().':'.$e->getLine(),
                    ] : null,
                    'Terjadi kesalahan server',
                );
            }
        });
    })->create();
