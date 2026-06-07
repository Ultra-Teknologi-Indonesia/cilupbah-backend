<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class ReservedStockItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'reserved_stock_id',
        'item_id',
        'bin_id',
        'qty',
    ];

    public function reservedStock(): BelongsTo
    {
        return $this->belongsTo(ReservedStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }
}
