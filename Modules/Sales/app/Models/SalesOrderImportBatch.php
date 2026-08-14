<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $batch_no
 * @property string|null $executed_by
 * @property string $original_filename
 * @property string $stored_path
 * @property string $state
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $success_rows
 * @property int $failed_rows
 * @property int $progress_percent
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
