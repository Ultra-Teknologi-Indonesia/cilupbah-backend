<?php

namespace Modules\Outbound\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkRtsBatch extends Model
{
    use HasUuid7;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'total_count',
        'success_count',
        'failed_count',
        'skipped_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'total_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'skipped_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BulkRtsItem::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recomputeCounts(): void
    {
        $success = $this->items()->where('status', BulkRtsItem::STATUS_SUCCESS)->count();
        $failed = $this->items()->where('status', BulkRtsItem::STATUS_FAILED)->count();
        $skipped = $this->items()->where('status', BulkRtsItem::STATUS_SKIPPED)->count();
        $pending = $this->items()->whereIn('status', [BulkRtsItem::STATUS_PENDING, BulkRtsItem::STATUS_PROCESSING])->count();

        $status = $pending === 0
            ? ($failed > 0 && $success === 0 ? self::STATUS_FAILED : self::STATUS_COMPLETED)
            : self::STATUS_PROCESSING;

        $this->update([
            'success_count' => $success,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
            'finished_at' => $pending === 0 ? now() : null,
        ]);
    }
}
