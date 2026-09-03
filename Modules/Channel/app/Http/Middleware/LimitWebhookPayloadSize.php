<?php

namespace Modules\Channel\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitWebhookPayloadSize
{
    use ApiResponse;

    private const MAX_BYTES = 1048576;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > self::MAX_BYTES) {
            return $this->errorResponse(
                'Payload terlalu besar (maksimal 1MB).',
                413,
                null,
                'Payload terlalu besar',
                'PAYLOAD_TOO_LARGE',
            );
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return $this->errorResponse(
                'Payload terlalu besar (maksimal 1MB).',
                413,
                null,
                'Payload terlalu besar',
                'PAYLOAD_TOO_LARGE',
            );
        }

        return $next($request);
    }
}
