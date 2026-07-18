<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum hanya memakai abilities saat kode memanggil tokenCan() atau rute
 * memasang middleware abilities:. Tanpa itu, refresh token (ability
 * ['refresh']) bisa memanggil seluruh endpoint biasa seperti access token
 * penuh. Middleware ini yang menegakkan batas tersebut.
 *
 * Token dibaca langsung dari header Authorization, bukan lewat $request->user(),
 * supaya tidak bergantung pada urutan middleware auth:sanctum.
 */
class RejectNonAccessToken
{
    use ApiResponse;

    /** Rute yang memang dirancang untuk dipanggil dengan refresh token. */
    private const REFRESH_ROUTE_NAMES = ['auth.refresh', 'api.auth.refresh'];

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);

        // Token tidak dikenal / kedaluwarsa — biar auth:sanctum yang menolak
        // supaya bentuk errornya konsisten.
        if (! $token) {
            return $next($request);
        }

        $abilities = $token->abilities ?? [];

        if (in_array('*', $abilities, true)) {
            return $next($request);
        }

        // Cek nama rute; fallback ke path supaya tetap benar kalau middleware
        // ini kebetulan berjalan sebelum rute ter-resolve.
        if (in_array($request->route()?->getName(), self::REFRESH_ROUTE_NAMES, true)
            || $request->is('api/*/auth/refresh')) {
            return $next($request);
        }

        return $this->errorResponse(
            'Token ini tidak berlaku untuk endpoint ini.',
            401,
            null,
            'Sesi berakhir',
        );
    }
}
