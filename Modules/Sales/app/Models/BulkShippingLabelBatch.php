<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class BulkShippingLabelBatch extends Model
{
    use HasUuid7;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'per_channel_opts',
        'total_count',
        'done_count',
        'failed_count',
        'skipped_count',
        'merged_pdf_path',
        'merged_pdf_bytes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'per_channel_opts' => 'array',
        'total_count' => 'integer',
        'done_count' => 'integer',
        'failed_count' => 'integer',
        'skipped_count' => 'integer',
        'merged_pdf_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BulkShippingLabelItem::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recomputeCounts(): void
    {
        $done = $this->items()->where('status', BulkShippingLabelItem::STATUS_DONE)->count();
        $failed = $this->items()->where('status', BulkShippingLabelItem::STATUS_FAILED)->count();
        $skipped = $this->items()->where('status', BulkShippingLabelItem::STATUS_SKIPPED_INSTANT)->count();
        $this->update([
            'done_count' => $done,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
        ]);
    }
}
