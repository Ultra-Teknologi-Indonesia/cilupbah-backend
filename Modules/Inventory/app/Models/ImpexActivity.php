<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImpexActivity extends Model
{
    use HasUuid7;

    public const DIRECTION_IMPORT = 'import';
    public const DIRECTION_EXPORT = 'export';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'direction',
        'activity_type',
        'source_type',
        'source_id',
        'user_id',
        'location_name',
        'status',
        'progress_percentage',
        'file_url',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress_percentage' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(ImpexActivityDetail::class, 'impex_activity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
