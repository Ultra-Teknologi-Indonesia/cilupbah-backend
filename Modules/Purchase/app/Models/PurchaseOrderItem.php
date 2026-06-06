<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchaseOrderItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'qty',
        'received_qty',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal'   => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\Product::class, 'item_id');
    }

    public function isFullyReceived(): bool
    {
        return $this->received_qty >= $this->qty;
    }

    public function pendingQty(): int
    {
        return max(0, $this->qty - $this->received_qty);
    }
}
