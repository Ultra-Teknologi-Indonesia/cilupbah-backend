<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Warehouse\Models\Location;
use App\Traits\HasUuid7;

class InventoryTransfer extends Model
{
    use HasUuid7;

    protected $fillable = [
        'transfer_number',
        'receive_number',
        'source_location_id',
        'destination_location_id',
        'status',
        'notes',
        'created_by',
        'received_by',
        'shipped_at',
        'received_at',
        'printed_by',
        'printed_at',
    ];

    protected $casts = [
        'shipped_at'  => 'datetime',
        'received_at' => 'datetime',
        'printed_at'  => 'datetime',
    ];

    const STATUS_DRAFT      = 'DRAFT';
    const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    const STATUS_RECEIVED   = 'RECEIVED';
    const STATUS_CANCELLED  = 'CANCELLED';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', self::STATUS_IN_TRANSIT);
    }
}
