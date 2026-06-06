<?php

namespace Modules\Inbound\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundAssignment extends Model
{
    use HasUuid7;

    const STATUS_PENDING    = 'PENDING';
    const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const STATUS_COMPLETED  = 'COMPLETED';

    protected $fillable = [
        'inbound_id',
        'assigned_to',
        'assigned_by',
        'status',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
