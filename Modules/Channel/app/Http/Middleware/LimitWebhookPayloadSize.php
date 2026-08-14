<?php

namespace Modules\Channel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitWebhookPayloadSize
{
    private const MAX_BYTES = 1048576; 

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > self::MAX_BYTES) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Payload terlalu besar (maksimal 1MB).',
            ], 413);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Payload terlalu besar (maksimal 1MB).',
            ], 413);
        }

        return $next($request);
    }
}
