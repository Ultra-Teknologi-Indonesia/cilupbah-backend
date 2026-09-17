<?php

namespace Modules\Realtime\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Modules\Realtime\Services\SseConnectionLimiter;
use Modules\Report\Models\ExportJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventStreamController extends Controller
{
    public function __construct(
        private readonly RealtimeEventPublisher $publisher,
        private readonly SseConnectionLimiter $connectionLimiter,
    ) {}

    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        if (! config('realtime.enabled', true)) {
            return response()->json([
                'message' => 'Realtime sedang dinonaktifkan sementara.',
            ], 429, ['Retry-After' => '30']);
        }

        $batchIds = array_values(array_filter(
            (array) $request->query('bulk_label_batch_id', []),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
        $exportIds = array_values(array_filter(
            (array) $request->query('export_id', []),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        $data = Validator::make([
            'bulk_label_batch_id' => $batchIds,
            'export_id' => $exportIds,
            'last_event_id' => $request->query('last_event_id', $request->header('Last-Event-ID')),
        ], [
            'bulk_label_batch_id' => ['array', 'max:20'],
            'bulk_label_batch_id.*' => ['uuid'],
            'export_id' => ['array', 'max:20'],
            'export_id.*' => ['uuid'],
            'last_event_id' => ['nullable', 'regex:/^\d+-\d+$/'],
        ])->validate();

        $topics = [];

        if ($batchIds !== []) {
            $owned = BulkShippingLabelBatch::query()
                ->whereIn('id', $batchIds)
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            abort_unless(count($owned) === count($batchIds), 403);
            foreach ($batchIds as $batchId) {
                $topics[] = 'bulk-label:'.$batchId;
            }
        }

        if ($exportIds !== []) {
            $owned = ExportJob::query()
                ->whereIn('id', $exportIds)
                ->where('user_id', $request->user()->id)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            abort_unless(count($owned) === count($exportIds), 403);
            foreach ($exportIds as $exportId) {
                $topics[] = 'export:'.$exportId;
            }
        }

        if ($topics === []) {
            return response()->json([
                'message' => 'Minimal satu resource realtime diperlukan.',
            ], 422);
        }

        $key = 'realtime-stream:'.$request->user()->id;
        try {
            if (RateLimiter::tooManyAttempts($key, 12)) {
                return response()->json([
                    'message' => 'Terlalu banyak koneksi realtime. Coba lagi sebentar.',
                ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Realtime sedang dibatasi sementara. Coba lagi sebentar.',
            ], 429, ['Retry-After' => '10']);
        }

        $lease = $this->connectionLimiter->acquire();
        if ($lease === null) {
            return response()->json([
                'message' => 'Kapasitas realtime sedang penuh. Proses utama tetap berjalan.',
            ], 429, ['Retry-After' => (string) $this->connectionLimiter->retryAfterSeconds()]);
        }

        $userId = (string) $request->user()->id;
        $streamKey = $this->publisher->streamKey($userId);
        $lastEventId = $data['last_event_id'] ?? null;

        $response = new StreamedResponse(function () use ($streamKey, $topics, $lastEventId, $lease): void {
            try {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');

            $cursor = $lastEventId ?: '$';
            $deadline = microtime(true) + max(5, (int) config('realtime.stream_max_seconds', 20));
            $heartbeatSeconds = max(2, (int) config('realtime.heartbeat_seconds', 8));
            $lastOutput = microtime(true);

            echo "retry: 5000\n\n";
            @ob_flush();
            flush();

            $this->writeEvent('connected', [
                'server_time' => now()->toIso8601String(),
            ]);

            while (! connection_aborted() && microtime(true) < $deadline) {
                try {
                    $entries = Redis::connection(config('realtime.redis_connection', 'default'))
                        ->xread(
                            [$streamKey => $cursor],
                            max(1, (int) config('realtime.max_events_per_read', 50)),
                            max(1000, (int) config('realtime.read_block_milliseconds', 4000)),
                        );
                } catch (\Throwable $e) {
                    report($e);
                    $this->writeEvent('realtime.error', [
                        'retryable' => true,
                    ]);
                    break;
                }

                $published = false;
                foreach ($this->normaliseEntries($entries, $streamKey) as $entry) {
                    $cursor = $entry['id'];
                    if (! in_array($entry['topic'], $topics, true)) {
                        continue;
                    }

                    $payload = json_decode($entry['payload'], true);
                    if (! is_array($payload)) {
                        continue;
                    }

                    $this->writeEvent(
                        $entry['event_type'],
                        $payload,
                        $entry['id'],
                    );
                    $published = true;
                    $lastOutput = microtime(true);
                }

                if (! $published && microtime(true) - $lastOutput >= $heartbeatSeconds) {
                    echo ': heartbeat '.now()->toIso8601String()."\n\n";
                    @ob_flush();
                    flush();
                    $lastOutput = microtime(true);
                }
            }
            } finally {
                $this->connectionLimiter->release($lease);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function writeEvent(string $event, array $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo 'id: '.$id."\n";
        }
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
        @ob_flush();
        flush();
    }

    /**
     * Normalise phpredis XREAD output into a stable internal representation.
     *
     * @return list<array{id: string, topic: string, event_type: string, payload: string}>
     */
    private function normaliseEntries(mixed $entries, string $streamKey): array
    {
        $streamEntries = is_array($entries) ? ($entries[$streamKey] ?? []) : [];
        $normalised = [];

        foreach ($streamEntries as $id => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $normalised[] = [
                'id' => (string) $id,
                'topic' => (string) ($fields['topic'] ?? ''),
                'event_type' => (string) ($fields['event_type'] ?? 'realtime'),
                'payload' => (string) ($fields['payload'] ?? '{}'),
            ];
        }

        return $normalised;
    }
}
