<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $batch_no
 * @property string|null $executed_by
 * @property string $original_filename
 * @property string $stored_path
 * @property string $state
 * @property int $total_rows
 * @property int $place_rows
 * @property int $manual_move_rows
 * @property int $already_rows
 * @property int $error_rows
 * @property int $processed_rows
 * @property int $success_rows
 * @property int $failed_rows
 * @property int $progress_percent
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class RackImportBatch extends Model
{
    use HasUuid7;

    public const STATE_QUEUED = 'queued';
    public const STATE_PREVIEWING = 'previewing';
    public const STATE_PREVIEWED = 'previewed';
    public const STATE_CONFIRMING = 'confirming';
    public const STATE_DONE = 'done';
    public const STATE_DONE_WITH_ERRORS = 'done_with_errors';
    public const STATE_FAILED = 'failed';

    public const STATUS_PLACE = 'place';
    public const STATUS_MANUAL_MOVE = 'manual_move';
    public const STATUS_ALREADY = 'already';
    public const STATUS_ERROR = 'error';

    public const STATUS_PLACED = 'placed';

    protected $fillable = [
        'batch_no',
        'executed_by',
        'original_filename',
        'stored_path',
        'state',
        'total_rows',
        'place_rows',
        'manual_move_rows',
        'already_rows',
        'error_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'progress_percent',
        'error_message',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'place_rows' => 'integer',
        'manual_move_rows' => 'integer',
        'already_rows' => 'integer',
        'error_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
        'progress_percent' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(RackImportRow::class, 'batch_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
