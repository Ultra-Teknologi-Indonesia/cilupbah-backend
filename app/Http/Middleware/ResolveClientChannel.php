<?php

namespace App\Http\Middleware;

use App\Enums\ClientChannelEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveClientChannel
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = strtoupper((string) $request->header('X-Client-Channel', ''));

        $channel = match ($header) {
            'MOBILE' => ClientChannelEnum::MOBILE,
            'WEB'    => ClientChannelEnum::WEB,
            default  => $this->inferFromRoute($request),
        };

        $request->attributes->set('client_channel', $channel);

        return $next($request);
    }

    private function inferFromRoute(Request $request): ClientChannelEnum
    {
        if ($request->is('api/mobile/*') || $request->is('api/v*/mobile/*')) {
            return ClientChannelEnum::MOBILE;
        }

        return ClientChannelEnum::WEB;
    }
}
