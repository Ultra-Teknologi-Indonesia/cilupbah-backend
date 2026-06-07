<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class ReservedStock extends Model
{
    use HasUuid7, SoftDeletes;

    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_EXPIRED = 'EXPIRED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'reserved_stock_no',
        'location_id',
        'start_date',
        'end_date',
        'status',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReservedStockItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
