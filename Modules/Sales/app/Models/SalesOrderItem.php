<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\ProductVariant;

use App\Traits\HasUuid7;

class SalesOrderItem extends Model
{
    use HasUuid7;

    protected $table = 'sales_order_items';

    protected $fillable = [
        'order_id',
        'item_id',
        'channel_product_id',
        'sku',
        'description',
        'qty_in_base',
        'price',
        'disc',
        'disc_amount',
        'tax_amount',
        'amount',
        'shipper',
        'tracking_no',
    ];

    public function order(): BelongsTo
    {

        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class, 'item_id', 'item_id');
    }
}
