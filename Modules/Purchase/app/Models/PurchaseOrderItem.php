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
        'shipping_cost',
        'tax_id',
        'tax_amount',
        'amount',
    ];

    protected $casts = [
        'unit_price'    => 'decimal:2',
        'amount'        => 'decimal:2',
        'disc'          => 'decimal:2',
        'disc_amount'   => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'tax_amount'    => 'decimal:2',
    ];

    protected $appends = ['product'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function getProductAttribute(): ?array
    {
        // To avoid N+1 issues when not eager loaded
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

    public function isFullyReceived(): bool
    {
        return $this->received_qty >= $this->qty;
    }

    public function pendingQty(): int
    {
        return max(0, $this->qty - $this->received_qty);
    }

    /**
     * Harga pokok per unit (landed cost) untuk perhitungan HPP / moving average.
     * Formula: unit_price + (shipping_cost/qty) - (disc_amount/qty).
     * Tidak memasukkan pajak (asumsi tax masukan dapat dikreditkan).
     */
    public function getLandedCostPerUnitAttribute(): float
    {
        $qty = (float) $this->qty;
        $base = (float) $this->unit_price;
        $shippingPerUnit = $qty > 0 ? ((float) $this->shipping_cost) / $qty : 0;
        $discountPerUnit = $qty > 0 ? ((float) $this->disc_amount) / $qty : 0;

        return max(0, $base + $shippingPerUnit - $discountPerUnit);
    }
}
