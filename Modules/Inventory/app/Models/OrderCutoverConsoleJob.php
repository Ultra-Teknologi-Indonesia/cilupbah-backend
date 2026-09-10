<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class OrderCutoverConsoleJob extends Model
{
    use HasUuid7;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $table = 'order_cutover_console_jobs';

    protected $fillable = [
        'id', 'type', 'status', 'files', 'location_codes', 'cutoff_at', 'report',
        'report_disk', 'report_path', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'files' => 'array',
        'location_codes' => 'array',
        'cutoff_at' => 'datetime',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
