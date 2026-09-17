<?php

namespace Modules\Realtime\Services;

use Illuminate\Support\Facades\Redis;
use JsonException;

final class RealtimeEventPublisher
{
    public function publish(
        string $userId,
        string $topic,
        string $eventType,
        array $data,
    ): void {
        if (! config('realtime.enabled', true) || $userId === '') {
            return;
        }

        try {
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            Redis::connection(config('realtime.redis_connection', 'default'))
                ->xadd(
                    $this->streamKey($userId),
                    '*',
                    [
                        'topic' => $topic,
                        'event_type' => $eventType,
                        'payload' => $payload,
                        'created_at' => now()->toIso8601String(),
                    ],
                    max(100, (int) config('realtime.stream_max_length', 1000)),
                    true,
                );
        } catch (JsonException $e) {
            report($e);
        } catch (\Throwable $e) {

            report($e);
        }
    }

    public function streamKey(string $userId): string
    {
        $safeUserId = preg_replace('/[^A-Za-z0-9_-]/', '', $userId) ?: 'unknown';

        return sprintf('%s:%s', config('realtime.stream_prefix', 'realtime:user'), $safeUserId);
    }
}
