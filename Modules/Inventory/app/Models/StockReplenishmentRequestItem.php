<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReplenishmentRequestItem extends Model
{
    use HasUuid7;

    protected $table = 'stock_replenishment_request_items';

    protected $fillable = [
        'request_id',
        'item_id',
        'sku',
        'qty',
        'reason',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockReplenishmentRequest::class, 'request_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }
}
