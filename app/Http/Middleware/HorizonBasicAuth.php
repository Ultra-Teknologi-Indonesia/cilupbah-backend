<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class HorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'staging'])) {
            return $next($request);
        }

        $allowed = (array) config('horizon.allowed_emails', []);
        $email = $request->getUser();
        $password = $request->getPassword();

        $user = ($email !== null && in_array($email, $allowed, true))
            ? User::where('email', $email)->first()
            : null;

        if ($user === null || $password === null || ! Hash::check($password, (string) $user->password)) {
            return response('Unauthorized', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Horizon"',
            ]);
        }

        Auth::login($user);

        return $next($request);
    }
}
