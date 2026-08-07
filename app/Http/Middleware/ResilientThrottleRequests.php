<?php

namespace App\Http\Middleware;

use App\Support\ErrorReporter;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

class ResilientThrottleRequests extends ThrottleRequests
{
    public function handle($request, Closure $next, ...$args)
    {
        $entered = false;

        $guarded = function ($req) use ($next, &$entered) {
            $entered = true;
            return $next($req);
        };

        try {
            return parent::handle($request, $guarded, ...$args);
        } catch (\RedisException $e) {
            if ($entered) {
                throw $e;
            }

            ErrorReporter::captureThrottled(
                $e,
                'ratelimit:store-failure',
                ['error_kind' => 'ratelimit_store'],
            );

            return $next($request);
        }
    }
}
