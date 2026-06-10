<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\LocationBin;
use App\Traits\HasUuid7;

class StockRevaluationItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'stock_revaluation_id',
        'item_id',
        'bin_id',
        'qty',
        'old_cost',
        'new_cost',
    ];

    protected $casts = [
        'old_cost' => 'decimal:2',
        'new_cost' => 'decimal:2',
    ];

    public function revaluation(): BelongsTo
    {
        return $this->belongsTo(StockRevaluation::class, 'stock_revaluation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }
}
