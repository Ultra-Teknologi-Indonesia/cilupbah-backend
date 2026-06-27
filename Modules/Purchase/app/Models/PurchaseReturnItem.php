<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchaseReturnItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'purchase_return_id',
        'item_id',
        'qty',
        'unit_price',
        'subtotal',
        'condition',
        'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal'   => 'decimal:2',
    ];

    protected $appends = ['product'];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function getProductAttribute(): ?array
    {
        if (!$this->relationLoaded('variant') || !$this->variant) {
            return null;
        }

        return [
            'id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'name' => $this->variant->relationLoaded('product') && $this->variant->product 
                ? $this->variant->product->name 
                : null,
        ];
    }
}
