<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\Product;

use App\Traits\HasUuid7;

class OrderItem extends Model
{
    use HasUuid7;
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
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class, 'item_id', 'item_id');
    }
}
