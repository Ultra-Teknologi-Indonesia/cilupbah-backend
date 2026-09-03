<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NormalizePaginationParameters
{
    private const MAX_PER_PAGE = 500;

    public function handle(Request $request, Closure $next): Response
    {

        $value = $request->query->has('per_page')
            ? $request->query('per_page')
            : $request->query('limit');

        if (is_scalar($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_INT);
            $normalized = $normalized === false
                ? 1
                : max(1, min(self::MAX_PER_PAGE, (int) $normalized));

            $request->query->set('per_page', $normalized);
            $request->merge(['per_page' => $normalized]);

            if ($request->query->has('limit')) {
                $request->query->set('limit', $normalized);
                $request->merge(['limit' => $normalized]);
            }
        }

        return $next($request);
    }
}
