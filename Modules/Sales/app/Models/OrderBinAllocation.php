<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;

class OrderBinAllocation extends Model
{
    use HasUuid7;

    protected $table = 'order_bin_allocations';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'item_id',
        'location_id',
        'bin_id',
        'qty',
        'completed_by',
        'completed_at',
        'reversed_at',
        'reversed_by',
    ];

    protected $casts = [
        'qty' => 'integer',
        'completed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }
}
