<?php

namespace Modules\Report\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    use HasUuid7;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'export_jobs';

    protected $fillable = [
        'user_id',
        'type',
        'params',
        'status',
        'queue_connection',
        'queue_name',
        'file_disk',
        'file_path',
        'file_name',
        'file_size',
        'file_purged_at',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'params' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'file_purged_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->effectiveStatus(), [self::STATUS_READY, self::STATUS_FAILED, self::STATUS_EXPIRED], true);
    }

    public function effectiveStatus(): string
    {
        if ($this->file_purged_at !== null) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }
}
