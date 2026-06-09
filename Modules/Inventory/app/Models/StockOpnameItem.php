<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class StockOpnameItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'stock_opname_id',
        'item_id',
        'bin_id',
        'batch_no',
        'serial_no',
        'expired_date',
        'qty_system',
        'qty_actual',
        'qty_difference',
        'reason',
        'counted_by',
        'counted_at',
    ];

    protected $casts = [
        'expired_date' => 'datetime',
        'counted_at' => 'datetime',
    ];

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
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
