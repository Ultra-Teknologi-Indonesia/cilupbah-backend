<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class StockAdjustment extends Model
{
    use HasUuid7, SoftDeletes;

    protected $fillable = [
        'adjustment_no',
        'transaction_date',
        'location_id',
        'is_beginning_balance',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'is_beginning_balance' => 'boolean',
    ];

    public function scopeExcludeInboundQtyCorrections(Builder $query): Builder
    {
        return $query->whereNotExists(function ($subQuery) {
            $subQuery
                ->selectRaw('1')
                ->from('inbound_receipts')
                ->whereColumn('inbound_receipts.stock_adjustment_id', 'stock_adjustments.id')
                ->where('inbound_receipts.condition', 'ADJUSTMENT');
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
