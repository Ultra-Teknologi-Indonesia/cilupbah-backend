<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;

final class FinanceSyncControlService
{
    private const TABLE = 'finance_sync_states';

    public function dispatch(SalesOrder|string $order, bool $force = false): bool
    {
        $order = is_string($order) ? SalesOrder::query()->find($order) : $order;

        if (! $order || ! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return false;
        }

        $eligible = $force
            || $order->is_canceled
            || ! in_array(strtoupper((string) $order->channel_status), ['UNPAID', 'UNCONFIRMED'], true);

        if (! $eligible || ! $this->request($order, $force)) {
            return false;
        }

        $health = $this->health();
        if (! ($health['allowed'] ?? true)) {
            $this->deferForBackpressure($order->id);
            Log::warning('Finance sync dispatch deferred by backpressure', [
                'order_id' => $order->id,
                'queue_depth' => $health['queue_depth'] ?? null,
                'memory_ratio' => $health['memory_ratio'] ?? null,
            ]);

            return false;
        }

        try {
            SyncOrderFinanceJob::dispatch($order->id, $force)
                ->onConnection(config('queue.routing.channel_finance.connection', 'redis-finance'))
                ->onQueue(config('queue.routing.channel_finance.queue', 'channel-finance'))
                ->afterCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->markRetryable($order->id, $exception, 60);
            Log::error('Finance sync dispatch failed', [
                'order_id' => $order->id,
                'source' => $order->source,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function health(): array
    {
        $queue = (string) config('queue.routing.channel_finance.queue', config('finance_sync.queue', 'channel-finance'));
        $queueConnection = (string) config('queue.routing.channel_finance.connection', config('finance_sync.connection', 'redis-finance'));
        $redisConnection = (string) config("queue.connections.{$queueConnection}.connection", 'finance');
        $result = [
            'queue' => $queue,
            'queue_connection' => $queueConnection,
            'redis_connection' => $redisConnection,
            'queue_depth' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'memory_used_bytes' => null,
            'memory_max_bytes' => null,
            'memory_ratio' => null,
            'allowed' => true,
        ];

        if ($queueConnection === 'sync' || app()->environment('testing')) {
            return $result;
        }

        try {
            $redis = Redis::connection($redisConnection);
            $result['queue_depth'] = (int) $redis->llen('queues:'.$queue);
            $result['reserved'] = (int) $redis->zcard('queues:'.$queue.':reserved');
            $result['delayed'] = (int) $redis->zcard('queues:'.$queue.':delayed');
            $result['queue_depth'] += $result['reserved'] + $result['delayed'];

            $memory = $redis->info('memory');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            $result['memory_used_bytes'] = $used;
            $result['memory_max_bytes'] = $maximum;
            $result['memory_ratio'] = $maximum > 0 ? round($used / $maximum, 4) : null;

            $result['allowed'] = $result['queue_depth'] < (int) config('finance_sync.max_queue_depth', 5000)
                && ($result['memory_ratio'] === null
                    || $result['memory_ratio'] < (float) config('finance_sync.max_redis_memory_ratio', 0.80));
        } catch (\Throwable $exception) {
            $result['allowed'] = false;
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    public function request(SalesOrder $order, bool $force = false): bool
    {
        if (! $this->available()) {
            Log::warning('Finance sync control table unavailable; using legacy dispatch fallback', [
                'order_id' => $order->id,
            ]);

            return true;
        }

        $now = now();
        $requestKey = $this->requestKey($order);

        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'status' => 'pending',
            'request_key' => $requestKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $current = DB::table(self::TABLE)
            ->where('order_id', $order->id)
            ->first(['status', 'request_key']);

        if (! $force
            && $current?->status === 'succeeded'
            && hash_equals((string) $current->request_key, $requestKey)) {
            return false;
        }

        $staleAt = now()->subMinutes((int) config('finance_sync.stale_processing_minutes', 20));
        $query = DB::table(self::TABLE)
            ->where('order_id', $order->id)
            ->where(function ($builder) use ($staleAt): void {
                $builder->whereNotIn('status', ['queued', 'processing'])
                    ->orWhere(function ($stale) use ($staleAt): void {
                        $stale->where('status', 'queued')
                            ->where('updated_at', '<', $staleAt);
                    })
                    ->orWhere(function ($stale) use ($staleAt): void {
                        $stale->where('status', 'processing')
                            ->where('locked_at', '<', $staleAt);
                    });
            })
            ->where(function ($builder) use ($now): void {
                $builder->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            });

        if (! $force) {
            $query->where('status', '!=', 'dead_letter');
        }

        return $query->update([
            'status' => 'queued',
            'request_key' => $requestKey,
            'next_attempt_at' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]) === 1;
    }

    public function claim(string $orderId): bool
    {
        if (! $this->available()) {
            return true;
        }

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $claimed = DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'queued', 'waiting', 'failed'])
            ->where(function ($builder) use ($now): void {
                $builder->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            })
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => $now,
                'locked_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 1) {
            return true;
        }

        $state = DB::table(self::TABLE)->where('order_id', $orderId)->first(['status', 'locked_at']);

        if ($state?->status !== 'processing' || ! $state->locked_at) {
            return false;
        }

        $staleAt = now()->subMinutes((int) config('finance_sync.stale_processing_minutes', 20));

        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->where('status', 'processing')
            ->where('locked_at', '<', $staleAt)
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => $now,
                'locked_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function markWaiting(string $orderId, ?string $error, int $delayMinutes): void
    {
        $this->update($orderId, [
            'status' => 'waiting',
            'next_attempt_at' => now()->addMinutes(max(1, $delayMinutes)),
            'locked_at' => null,
            'last_error' => $this->truncate($error),
        ]);
    }

    public function markSucceeded(string $orderId): void
    {
        $this->update($orderId, [
            'status' => 'succeeded',
            'next_attempt_at' => null,
            'locked_at' => null,
            'last_error' => null,
            'last_success_at' => now(),
        ]);
    }

    public function markRetryable(string $orderId, \Throwable $exception, int $delaySeconds): void
    {
        $this->update($orderId, [
            'status' => 'failed',
            'next_attempt_at' => now()->addSeconds(max(1, $delaySeconds)),
            'locked_at' => null,
            'last_error' => $this->truncate($exception->getMessage()),
        ]);
    }

    public function markDeadLetter(string $orderId, \Throwable $exception, ?string $jobUuid, int $attempts, array $context = []): void
    {
        $current = $this->state($orderId);
        if ($current?->status === 'succeeded') {
            return;
        }

        $previousError = trim((string) ($current?->last_error ?? ''));
        $isAttemptsExceeded = $exception instanceof \Illuminate\Queue\MaxAttemptsExceededException;
        $rootCause = $isAttemptsExceeded && $previousError !== ''
            ? $previousError
            : $exception->getMessage();
        $rootCause = $this->truncate($rootCause, 4000);
        $context = array_merge($context, [
            'terminal_exception_class' => $exception::class,
            'terminal_exception_message' => $this->truncate($exception->getMessage(), 4000),
            'root_cause_preserved' => $isAttemptsExceeded && $previousError !== '',
        ]);

        $this->update($orderId, [
            'status' => 'dead_letter',
            'next_attempt_at' => null,
            'locked_at' => null,
            'last_error' => $this->truncate($rootCause),
        ]);

        if (! $this->deadLetterAvailable()) {
            return;
        }

        $order = SalesOrder::query()->find($orderId);
        $jobUuid = $jobUuid ?: (string) Str::uuid();

        DB::table('finance_sync_dead_letters')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'job_uuid' => $jobUuid,
            'order_id' => $orderId,
            'source' => $order?->source,
            'channel_order_no' => $order?->channel_order_no,
            'exception_class' => $exception::class,
            'attempts' => max(0, $attempts),
            'reason' => $rootCause,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function deferForBackpressure(string $orderId): void
    {
        $this->markWaiting($orderId, 'Finance queue backpressure aktif', (int) config('finance_sync.backpressure_retry_after_minutes', 5));
    }

    public function state(string $orderId): ?object
    {
        return $this->available()
            ? DB::table(self::TABLE)->where('order_id', $orderId)->first()
            : null;
    }

    private function update(string $orderId, array $values): void
    {
        if (! $this->available()) {
            return;
        }

        DB::table(self::TABLE)->where('order_id', $orderId)->update(array_merge($values, [
            'updated_at' => now(),
        ]));
    }

    private function requestKey(SalesOrder $order): string
    {
        return hash('sha256', implode('|', [
            $order->source,
            $order->channel_shop_id,
            $order->channel_order_no,
            $order->channel_status,
            optional($order->channel_updated_at)->toIso8601String(),
        ]));
    }

    private function truncate(?string $value, int $limit = 2000): ?string
    {
        return $value === null ? null : Str::limit($value, $limit, '');
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function deadLetterAvailable(): bool
    {
        try {
            return Schema::hasTable('finance_sync_dead_letters');
        } catch (\Throwable) {
            return false;
        }
    }
}
