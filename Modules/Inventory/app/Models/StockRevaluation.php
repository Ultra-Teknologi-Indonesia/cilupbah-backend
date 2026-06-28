<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Warehouse\Models\Location;
use App\Traits\HasUuid7;

class StockRevaluation extends Model
{
    use HasUuid7;

    protected $fillable = [
        'revaluation_no',
        'location_id',
        'status',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT     = 'DRAFT';
    const STATUS_APPROVED  = 'APPROVED';
    const STATUS_CANCELLED = 'CANCELLED';

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRevaluationItem::class);
    }
}
