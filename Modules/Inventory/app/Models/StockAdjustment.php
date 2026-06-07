<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class StockAdjustment extends Model
{
    use HasUuid7, SoftDeletes;

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'adjustment_no',
        'transaction_date',
        'location_id',
        'status',
        'is_beginning_balance',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'approved_at' => 'datetime',
        'is_beginning_balance' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
