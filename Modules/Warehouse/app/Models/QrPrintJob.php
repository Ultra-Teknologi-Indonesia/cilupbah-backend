<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QrPrintJob extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $table = 'qr_print_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'location_id',
        'user_id',
        'status',
        'paper',
        'bin_ids',
        'total_bins',
        'processed_bins',
        'file_path',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'bin_ids' => 'array',
        'total_bins' => 'integer',
        'processed_bins' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function progressPercent(): int
    {
        if ($this->total_bins <= 0) {
            return $this->status === self::STATUS_READY ? 100 : 0;
        }

        $pct = (int) floor(($this->processed_bins / $this->total_bins) * 100);

        return min(max($pct, 0), 100);
    }
}
