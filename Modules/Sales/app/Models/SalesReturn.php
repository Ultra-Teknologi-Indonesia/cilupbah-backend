<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use App\Traits\HasUuid7;

class SalesReturn extends Model
{
    use HasUuid7;

    protected $fillable = [
        'return_number',
        'order_id',
        'location_id',
        'source',
        'channel_return_id',
        'channel_shop_id',
        'return_tracking_number',
        'return_carrier',
        'return_shipped_at',
        'tracking_synced_at',
        'customer_name',
        'customer_contact',
        'status',
        'reason',
        'notes',
        'created_by',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'return_shipped_at' => 'datetime',
        'tracking_synced_at' => 'datetime',
    ];

    const SOURCE_MANUAL      = 'manual';
    const SOURCE_MARKETPLACE = 'marketplace';

    const STATUS_PENDING   = 'PENDING';
    const STATUS_ACCEPTED  = 'ACCEPTED';
    const STATUS_REJECTED  = 'REJECTED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELLED = 'CANCELLED';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('sales_returns.status', $status);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('sales_returns.status', self::STATUS_PENDING);
    }

    public function scopeMarketplace($query)
    {
        return $query->where('sales_returns.source', self::SOURCE_MARKETPLACE);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(SalesReturnSettlement::class, 'return_id');
    }
}
