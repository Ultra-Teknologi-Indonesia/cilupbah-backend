<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class Putaway extends Model
{
    use HasUuid7;

    const STATUS_NOT_STARTED = 'NOT_STARTED';
    const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'putaway_no',
        'location_id',
        'source_type',
        'source_id',
        'status',
        'assigned_to',
        'assigned_by',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PutawayItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inbound\Models\Inbound::class, 'source_id');
    }

    /**
     * Baris pivot penerimaan sumber (mendukung 1 putaway dari banyak penerimaan).
     */
    public function sourceRows(): HasMany
    {
        return $this->hasMany(PutawaySource::class);
    }

    /**
     * Daftar penerimaan (inbound) yang digabung ke dalam putaway ini.
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Inbound\Models\Inbound::class,
            'putaway_sources',
            'putaway_id',
            'inbound_id',
        )->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
