<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

class StockReplenishmentRequestItem extends Model
{
    use HasUuid7;

    protected $table = 'stock_replenishment_request_items';

    protected $fillable = [
        'request_id',
        'item_id',
        'sku',
        'qty',
        'demand_qty',
        'available_qty',
        'in_flight_qty',
        'suggested_qty',
        'reason',
    ];

    protected $casts = [
        'qty' => 'integer',
        'demand_qty' => 'integer',
        'available_qty' => 'integer',
        'in_flight_qty' => 'integer',
        'suggested_qty' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockReplenishmentRequest::class, 'request_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }
}
