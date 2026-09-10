<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Enums\WebhookInboxStatus;

class ChannelWebhookInbox extends Model
{
    use HasUuid7;

    protected $table = 'channel_webhook_inbox';

    protected $fillable = [
        'channel',
        'shop_id',
        'event_key',
        'event_type',
        'channel_return_id',
        'payload',
        'status',
        'attempts',
        'error',
        'received_at',
        'processed_at',
        'next_attempt_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => WebhookInboxStatus::class,
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function markProcessed(): void
    {
        $this->update([
            'status' => WebhookInboxStatus::PROCESSED,
            'processed_at' => now(),
            'next_attempt_at' => null,
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => WebhookInboxStatus::FAILED,
            'attempts' => $this->attempts + 1,
            'error' => mb_substr($message, 0, 2000),
            'next_attempt_at' => null,
        ]);
    }

    public static function markDispatchFailedByKey(string $eventKey, string $message): void
    {
        DB::transaction(function () use ($eventKey, $message): void {
            $row = static::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->status !== WebhookInboxStatus::RECEIVED) {
                return;
            }

            $attempts = (int) $row->attempts + 1;
            $delaySeconds = min(3600, 30 * (2 ** min($attempts - 1, 7)));

            $row->update([
                'attempts' => $attempts,
                'error' => mb_substr($message, 0, 2000),
                'next_attempt_at' => now()->addSeconds($delaySeconds),
            ]);
        });
    }

    public static function markDispatchQueuedByKey(string $eventKey): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->where('status', WebhookInboxStatus::RECEIVED)
            ->update([
                'error' => null,
                'next_attempt_at' => now()->addMinutes(10),
            ]);
    }

    public static function markReplayAttemptByKey(string $eventKey): void
    {
        DB::transaction(function () use ($eventKey): void {
            $row = static::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->status !== WebhookInboxStatus::RECEIVED) {
                return;
            }

            $attempts = (int) $row->attempts + 1;
            $row->update([
                'attempts' => $attempts,

                'next_attempt_at' => now()->addMinutes(10),
            ]);
        });
    }

    public static function claimReplayBatch(
        \DateTimeInterface $threshold,
        int $maxAttempts,
        int $limit,
        int $leaseSeconds = 600,
    ): Collection {
        return DB::transaction(function () use ($threshold, $maxAttempts, $limit, $leaseSeconds): Collection {
            $rows = static::query()
                ->where('status', WebhookInboxStatus::RECEIVED)
                ->where('received_at', '<', $threshold)
                ->where('attempts', '<', $maxAttempts)
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('received_at')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            static::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update([
                    'next_attempt_at' => now()->addSeconds($leaseSeconds),
                    'updated_at' => now(),
                ]);

            return $rows;
        });
    }

    public static function markProcessedByKey(string $eventKey): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->update([
                'status' => WebhookInboxStatus::PROCESSED,
                'processed_at' => now(),
                'next_attempt_at' => null,
            ]);
    }

    public static function markSkippedByKey(string $eventKey, string $reason): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->update([
                'status' => WebhookInboxStatus::SKIPPED,
                'processed_at' => now(),
                'error' => mb_substr($reason, 0, 2000),
                'next_attempt_at' => null,
            ]);
    }

    public static function markFailedByKey(string $eventKey, string $message): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->update([
                'status' => WebhookInboxStatus::FAILED,
                'error' => mb_substr($message, 0, 2000),
                'next_attempt_at' => null,
            ]);
    }
}
