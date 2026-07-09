<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderImportBatch extends Model
{
    use HasUuid7;

    public const STATE_QUEUED = 'queued';
    public const STATE_PROCESSING = 'processing';
    public const STATE_DONE = 'done';
    public const STATE_DONE_WITH_ERRORS = 'done_with_errors';
    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'batch_no',
        'executed_by',
        'original_filename',
        'stored_path',
        'state',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'progress_percent',
        'error_message',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
        'progress_percent' => 'integer',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(SalesOrderImportError::class, 'import_batch_id');
    }
}
