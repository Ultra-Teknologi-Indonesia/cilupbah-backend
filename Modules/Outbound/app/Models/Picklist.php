<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class Picklist extends Model
{
    use HasUuid7;

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_FAILED = 'FAILED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'picklist_no',
        'location_id',
        'picker_id',
        'assigned_by',
        'status',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'has_instant' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PicklistItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'picker_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
