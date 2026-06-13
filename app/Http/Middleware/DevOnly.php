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

        if (! in_array(app()->environment(), $allowed, true) && ! config('devtracker.enabled')) {
            abort(404);
        }

        $user = config('devtracker.basic_auth.user');
        $pass = config('devtracker.basic_auth.pass');
        if ($user && $pass) {
            if ($request->getUser() !== $user || $request->getPassword() !== $pass) {
                return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Dev Tracker"']);
            }
        }

        return $next($request);
    }
}
