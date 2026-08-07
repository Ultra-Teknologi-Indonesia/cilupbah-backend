<?php

namespace App\Http\Middleware;

use App\Support\ErrorReporter;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

// Throttle yang fail-open: bila penyimpanan penghitung (Redis) gagal diakses,
// request tetap diloloskan alih-alih memblokir semua pengguna. Gangguan pada
// penghitung tidak boleh menghentikan operasi gudang.
//
// Aman terhadap "double run": kegagalan hanya di-fail-open bila terjadi SEBELUM
// controller dijalankan. Jika error datang dari controller ($next), ia dilempar
// ulang apa adanya — bukan ditelan atau dijalankan dua kali.
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
