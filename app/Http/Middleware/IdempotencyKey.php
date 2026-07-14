<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey as IdempotencyKeyModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('X-Request-Id', ''));
        if ($key === '') {
            return $next($request);
        }

        $endpoint = $request->method() . ' ' . rtrim($request->path(), '/');
        $ttlHours = (int) config('warehouse.idempotency_ttl_hours', 24);

        $existing = IdempotencyKeyModel::query()
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing && $existing->response_status !== null) {
            return response()->json(
                $existing->response_body,
                $existing->response_status,
                ['X-Idempotent-Replay' => 'true'],
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            IdempotencyKeyModel::updateOrCreate(
                ['key' => $key, 'endpoint' => $endpoint],
                [
                    'user_id' => optional($request->user())->id,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'expires_at' => now()->addHours($ttlHours),
                ],
            );
        }

        return $response;
    }
}
