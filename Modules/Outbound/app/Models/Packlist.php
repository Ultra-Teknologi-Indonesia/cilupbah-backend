<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class Packlist extends Model
{
    use HasUuid7;

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'packlist_no',
        'location_id',
        'packer_id',
        'assigned_by',
        'order_id',
        'picklist_id',
        'status',
        'started_at',
        'completed_at',
        'package_count',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PacklistItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function packer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'packer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Order\Models\Order::class);
    }

    public function picklist(): BelongsTo
    {
        return $this->belongsTo(Picklist::class);
    }
}
