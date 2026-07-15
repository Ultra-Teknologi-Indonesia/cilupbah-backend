<?php

namespace Modules\Inbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundParticipant extends Model
{
    use HasUuid7;

    const STATUS_ACTIVE    = 'ACTIVE';
    const STATUS_DONE      = 'DONE';
    const STATUS_WITHDRAWN = 'WITHDRAWN';

    const ROLE_RECEIVER = 'RECEIVER';

    protected $fillable = [
        'inbound_id',
        'user_id',
        'role',
        'joined_at',
        'completed_at',
        'status',
        'withdrawn_by',
        'withdraw_reason',
        'withdrawn_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'completed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'withdrawn_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
