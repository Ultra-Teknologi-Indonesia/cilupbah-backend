<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevOnly
{

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('devtracker.allowed_envs', ['local', 'staging']);
        $inAllowedEnv = in_array(app()->environment(), $allowed, true);

        if (! $inAllowedEnv && ! config('devtracker.enabled')) {
            abort(404);
        }

        $user = config('devtracker.basic_auth.user');
        $pass = config('devtracker.basic_auth.pass');

        // Di environment yang TIDAK diizinkan, kita hanya sampai di sini lewat override
        // DEVTRACKER_ENABLED. Di situ Basic auth wajib — kalau kredensialnya tidak
        // dikonfigurasi, tolak, jangan biarkan tracker terbuka tanpa cek apa pun.
        if (! $inAllowedEnv && (! $user || ! $pass)) {
            abort(404);
        }

        if ($user && $pass) {
            if (! hash_equals($user, (string) $request->getUser())
                || ! hash_equals($pass, (string) $request->getPassword())) {
                return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Dev Tracker"']);
            }
        }

        return $next($request);
    }
}
