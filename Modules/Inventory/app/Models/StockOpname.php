<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class StockOpname extends Model
{
    use HasUuid7, SoftDeletes;

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const STATUS_FINALIZED = 'FINALIZED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'opname_no',
        'location_id',
        'filter_zone_id',
        'filter_floor_codes',
        'filter_row_codes',
        'filter_column_codes',
        'status',
        'notes',
        'created_by',
        'process_by',
        'finalized_by',
        'finalized_at',
        'printed_by',
        'printed_at',
    ];

    protected $casts = [
        'filter_floor_codes' => 'array',
        'filter_row_codes' => 'array',
        'filter_column_codes' => 'array',
        'finalized_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationZone::class, 'filter_zone_id');
    }
}
