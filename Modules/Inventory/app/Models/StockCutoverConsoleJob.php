<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class StockCutoverConsoleJob extends Model
{
    use HasUuid7;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'stock_cutover_console_jobs';

    protected $fillable = [
        'id', 'type', 'status', 'source_job_id', 'files', 'report', 'report_disk',
        'report_path', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'files' => 'array',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
