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
        'description',
        'unit',
        'qty',
        'received_qty',
        'unit_price',
        'disc',
        'disc_amount',
        'tax_id',
        'tax_amount',
        'amount',
    ];

    protected $casts = [
        'unit_price'  => 'decimal:2',
        'amount'      => 'decimal:2',
        'disc'        => 'decimal:2',
        'disc_amount' => 'decimal:2',
        'tax_amount'  => 'decimal:2',
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
