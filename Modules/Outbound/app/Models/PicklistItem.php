<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PicklistItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'picklist_id',
        'order_id',
        'order_item_id',
        'item_id',
        'sku',
        'bin_id',
        'qty_ordered',
        'qty_picked',
    ];

    public function picklist(): BelongsTo
    {
        return $this->belongsTo(Picklist::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderItem::class, 'order_item_id');
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
