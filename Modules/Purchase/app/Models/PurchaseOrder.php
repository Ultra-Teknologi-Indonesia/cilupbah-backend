<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supplier\Models\Supplier;
use Modules\Warehouse\Models\Location;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'supplier_id',
        'location_id',
        'status',
        'order_date',
        'expected_date',
        'total_amount',
        'payment_term',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'expected_date' => 'date',
        'total_amount'  => 'decimal:2',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIAL_RECEIVED]);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeReceivable($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIAL_RECEIVED]);
    }
}
