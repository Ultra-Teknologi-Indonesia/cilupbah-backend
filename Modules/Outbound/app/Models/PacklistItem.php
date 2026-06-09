<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PacklistItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'packlist_id',
        'order_item_id',
        'item_id',
        'sku',
        'qty_ordered',
        'qty_packed',
        'barcode_verified',
    ];

    protected $casts = [
        'barcode_verified' => 'boolean',
    ];

    public function packlist(): BelongsTo
    {
        return $this->belongsTo(Packlist::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }
}
