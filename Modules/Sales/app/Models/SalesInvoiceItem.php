<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class SalesInvoiceItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sales_invoice_id',
        'item_id',
        'qty',
        'unit_price',
        'disc_amount',
        'tax_amount',
        'subtotal',
    ];

    protected $casts = [
        'unit_price'  => 'decimal:2',
        'disc_amount' => 'decimal:2',
        'tax_amount'  => 'decimal:2',
        'subtotal'    => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }
}
