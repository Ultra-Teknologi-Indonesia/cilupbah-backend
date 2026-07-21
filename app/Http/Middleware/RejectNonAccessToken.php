<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectNonAccessToken
{
    use ApiResponse;

    private const REFRESH_ROUTE_NAMES = ['auth.refresh', 'api.auth.refresh'];

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);

        if (! $token) {
            return $next($request);
        }

        $abilities = $token->abilities ?? [];

        if (in_array('*', $abilities, true)) {
            return $next($request);
        }

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
