<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
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
        'payload',
        'status',
        'attempts',
        'error',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => WebhookInboxStatus::class,
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function markProcessed(): void
    {
        $this->update([
            'status' => WebhookInboxStatus::PROCESSED,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => WebhookInboxStatus::FAILED,
            'attempts' => $this->attempts + 1,
            'error' => mb_substr($message, 0, 2000),
        ]);
    }

    public static function markProcessedByKey(string $eventKey): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->update([
                'status' => WebhookInboxStatus::PROCESSED,
                'processed_at' => now(),
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
            ]);
    }

    public static function markFailedByKey(string $eventKey, string $message): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->update([
                'status' => WebhookInboxStatus::FAILED,
                'error' => mb_substr($message, 0, 2000),
            ]);
    }
}
