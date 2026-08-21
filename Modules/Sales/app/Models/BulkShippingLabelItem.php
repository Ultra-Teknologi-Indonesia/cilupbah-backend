<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkShippingLabelItem extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_WAITING_AWB = 'waiting_awb';
    public const STATUS_WAITING_SHOPEE_PREP = 'waiting_shopee_prep';
    public const STATUS_WAITING_LAZADA_PREP = 'waiting_lazada_prep';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_INSTANT = 'skipped_instant';

    public const TERMINAL_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED_INSTANT,
    ];

    public const TRANSIENT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DOWNLOADING,
        self::STATUS_WAITING_AWB,
        self::STATUS_WAITING_SHOPEE_PREP,
        self::STATUS_WAITING_LAZADA_PREP,
    ];

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DOWNLOADING,
        self::STATUS_WAITING_AWB,
        self::STATUS_WAITING_SHOPEE_PREP,
        self::STATUS_WAITING_LAZADA_PREP,
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED_INSTANT,
    ];

    public const REASON_NO_AWB = 'no_awb';
    public const REASON_CHANNEL_UNSUPPORTED = 'channel_unsupported';
    public const REASON_SHOPEE_PREP_TIMEOUT = 'shopee_prep_timeout';
    public const REASON_SHOPEE_PREP_FAILED = 'shopee_prep_failed';
    public const REASON_SHOPEE_DECODE_FAILED = 'shopee_decode_failed';
    public const REASON_SELF_DESIGN = 'self_design';
    public const REASON_BATCH_CRASHED = 'batch_crashed';
    public const REASON_STALE_BATCH_REAPED = 'stale_batch_reaped';
    public const REASON_INSTANT_COURIER = 'instant_courier_manual_dispatch';
    public const REASON_LAZADA_PREP_TIMEOUT = 'lazada_prep_timeout';
    public const REASON_LAZADA_PREP_FAILED = 'lazada_prep_failed';
    public const REASON_LAZADA_DECODE_FAILED = 'lazada_decode_failed';
    public const REASON_AWB_TIMEOUT = 'awb_timeout';
    public const REASON_CHANNEL_SYNC_PAUSED = 'channel_sync_paused';
    public const REASON_PARCEL_ALREADY_SHIPPED = 'parcel_already_shipped';

    public const RECOVERABLE_REASONS = [
        self::REASON_SHOPEE_PREP_TIMEOUT,
        self::REASON_SHOPEE_PREP_FAILED,
        self::REASON_SHOPEE_DECODE_FAILED,
        self::REASON_LAZADA_PREP_TIMEOUT,
        self::REASON_LAZADA_PREP_FAILED,
        self::REASON_LAZADA_DECODE_FAILED,
        self::REASON_BATCH_CRASHED,
        self::REASON_STALE_BATCH_REAPED,
        self::REASON_NO_AWB,
        self::REASON_AWB_TIMEOUT,
    ];

    protected $fillable = [
        'batch_id',
        'order_id',
        'channel',
        'status',
        'reason',
        'pdf_bytes',
        'downloaded_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
    ];

    protected $hidden = ['pdf_bytes'];

    protected function pdfBytes(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (is_resource($value)) {
                    rewind($value);
                    $value = stream_get_contents($value);
                }

                if (is_string($value) && str_starts_with($value, '\\x')) {
                    return hex2bin(substr($value, 2));
                }

                return $value;
            },
            set: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                if (is_resource($value)) {
                    rewind($value);
                    $value = stream_get_contents($value);
                }

                if (is_string($value) && str_starts_with($value, '\\x')) {
                    return $value;
                }

                return '\\x' . bin2hex($value);
            },
        )->shouldCache();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkShippingLabelBatch::class, 'batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function isRecoverable(): bool
    {
        return $this->status === self::STATUS_FAILED
            && in_array($this->reason, self::RECOVERABLE_REASONS, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isTransient(): bool
    {
        return in_array($this->status, self::TRANSIENT_STATUSES, true);
    }
}
