<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supplier\Models\Contact;
use Modules\Warehouse\Models\Location;
use App\Traits\HasUuid7;

class PurchaseOrder extends Model
{
    use HasUuid7;

    protected $fillable = [
        'po_number',
        'contact_id',
        'location_id',
        'status',
        'order_date',
        'expected_date',
        'ref_no',
        'payment_term',
        'is_tax_included',
        'sub_total',
        'total_disc',
        'total_tax',
        'total_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date'      => 'date',
        'expected_date'   => 'date',
        'total_amount'    => 'decimal:2',
        'sub_total'       => 'decimal:2',
        'total_disc'      => 'decimal:2',
        'total_tax'       => 'decimal:2',
        'is_tax_included' => 'boolean',
        'payment_term'    => 'integer',
    ];

    const STATUS_DRAFT             = 'DRAFT';
    const STATUS_OPEN              = 'OPEN';
    const STATUS_PARTIAL_RECEIVED  = 'PARTIAL_RECEIVED';
    const STATUS_FULLY_RECEIVED    = 'FULLY_RECEIVED';
    const STATUS_CANCELLED         = 'CANCELLED';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_PARTIAL_RECEIVED,
        self::STATUS_FULLY_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }


    public function isReceivable(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIAL_RECEIVED]);
    }

    public function recomputeStatus(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        $items = $this->items()->get(['id', 'qty', 'received_qty']);

        if ($items->isEmpty() || $items->sum('received_qty') <= 0) {
            return $this->status === self::STATUS_DRAFT
                ? self::STATUS_DRAFT
                : self::STATUS_OPEN;
        }

        $allReceived = $items->every(
            fn ($item) => (int) $item->received_qty >= (int) $item->qty
        );

        return $allReceived
            ? self::STATUS_FULLY_RECEIVED
            : self::STATUS_PARTIAL_RECEIVED;
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeReceivable($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIAL_RECEIVED]);
    }

    public function scopeWhereDateFrom($query, $date)
    {
        return $query->where('order_date', '>=', $date);
    }

    public function scopeWhereDateTo($query, $date)
    {
        return $query->where('order_date', '<=', $date);
    }
}
