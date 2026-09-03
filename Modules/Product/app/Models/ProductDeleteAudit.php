<?php

declare(strict_types=1);

namespace Modules\Product\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductDeleteAudit extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    public const MEDIA_CLEANUP_PENDING = 'pending';

    public const MEDIA_CLEANUP_SUCCEEDED = 'succeeded';

    public const MEDIA_CLEANUP_FAILED = 'failed';

    protected $table = 'product_delete_audits';

    protected $fillable = [
        'batch_id',
        'actor_id',
        'actor_name',
        'actor_email',
        'request_id',
        'status',
        'requested_count',
        'product_snapshots',
        'failure_code',
        'failure_message',
        'media_cleanup_status',
        'media_cleanup_error',
        'completed_at',
    ];

    protected $casts = [
        'product_snapshots' => 'array',
        'completed_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
