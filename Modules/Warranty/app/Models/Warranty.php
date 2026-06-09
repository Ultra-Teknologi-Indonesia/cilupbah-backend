<?php

namespace Modules\Warranty\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    use HasUuid7;

    protected $fillable = [
        'product_variant_id',
        'order_id',
        'serial_no',
        'duration_months',
        'start_date',
        'end_date',
        'status',
        'notes',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id');
    }
}
