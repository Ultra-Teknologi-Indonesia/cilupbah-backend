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
        'contact_id',
        'location_id',
        'status',
        'bill_date',
        'due_date',
        'ref_no',
        'payment_term',
        'is_tax_included',
        'sub_total',
        'total_disc',
        'total_tax',
        'total_amount',
        'paid_amount',
        'tag',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'bill_date'       => 'date',
        'due_date'        => 'date',
        'total_amount'    => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'sub_total'       => 'decimal:2',
        'total_disc'      => 'decimal:2',
        'total_tax'       => 'decimal:2',
        'is_tax_included' => 'boolean',
        'payment_term'    => 'integer',
    ];

    const STATUS_DRAFT     = 'DRAFT';
    const STATUS_OPEN      = 'OPEN';
    const STATUS_PAID      = 'PAID';
    const STATUS_PARTIAL   = 'PARTIAL';
    const STATUS_CANCELLED = 'CANCELLED';

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Contact::class);
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

    public function scopeWhereDateFrom($query, $date)
    {
        return $query->where('bill_date', '>=', $date);
    }

    public function scopeWhereDateTo($query, $date)
    {
        return $query->where('bill_date', '<=', $date);
    }

    public function remainingAmount(): float
    {
        return max(0, $this->total_amount - $this->paid_amount);
    }
}
