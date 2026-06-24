<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class PurchaseBillItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'purchase_bill_id',
        'purchase_order_item_id',
        'item_id',
        'description',
        'unit',
        'qty',
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

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(PurchaseSerialNumber::class, 'purchase_bill_item_id');
    }
}
