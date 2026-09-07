<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StockCutoverConsoleToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('operations.stock_cutover_console.token', '');
        $provided = (string) $request->route('token', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        return $next($request)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
