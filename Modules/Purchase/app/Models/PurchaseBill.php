<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class PurchaseBill extends Model
{
    use HasUuid7;

    protected $fillable = [
        'bill_number',
        'purchase_order_id',
        'supplier_id',
        'location_id',
        'status',
        'bill_date',
        'due_date',
        'total_amount',
        'paid_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'bill_date'    => 'date',
        'due_date'     => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
    ];

    const STATUS_DRAFT     = 'DRAFT';
    const STATUS_OPEN      = 'OPEN';
    const STATUS_PAID      = 'PAID';
    const STATUS_CANCELLED = 'CANCELLED';

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseBillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }
}
