<?php

namespace Modules\Outbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sales\Models\SalesOrder;

class BulkRtsItem extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'batch_id',
        'order_id',
        'salesorder_no',
        'source',
        'status',
        'message',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkRtsBatch::class, 'batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }
}
