<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class SalesReturnItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sales_return_id',
        'item_id',
        'qty',
        'approved_qty',
        'condition',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
        'approved_qty' => 'integer',
    ];

    /**
     * Qty yang disetujui untuk restock; fallback ke qty bila belum di-set (data lama / belum diproses).
     */
    public function approvedQty(): int
    {
        return $this->approved_qty ?? (int) $this->qty;
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }
}
