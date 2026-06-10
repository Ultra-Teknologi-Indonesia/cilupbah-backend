<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\HasUuid7;

class PurchaseReturn extends Model
{
    use HasUuid7;

    protected $fillable = [
        'return_number',
        'purchase_order_id',
        'supplier_id',
        'location_id',
        'status',
        'return_date',
        'total_amount',
        'reason',
        'notes',
        'created_by',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'return_date'   => 'date',
        'total_amount'  => 'decimal:2',
        'processed_at'  => 'datetime',
    ];

    const STATUS_DRAFT     = 'DRAFT';
    const STATUS_SUBMITTED = 'SUBMITTED';
    const STATUS_APPROVED  = 'APPROVED';
    const STATUS_COMPLETED = 'COMPLETED';
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
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(PurchaseReturnSettlement::class, 'return_id');
    }
}
