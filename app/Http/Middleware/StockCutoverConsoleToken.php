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
        $routeName = (string) ($request->route()?->getName() ?? '');
        $configPrefix = str_starts_with($routeName, 'operations.order-cutover.')
            ? 'operations.order_cutover_console'
            : 'operations.stock_cutover_console';
        $expected = (string) config($configPrefix.'.token', '');
        $provided = (string) $request->route('token', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
