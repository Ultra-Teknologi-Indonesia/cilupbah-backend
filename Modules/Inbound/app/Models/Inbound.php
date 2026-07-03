<?php

namespace Modules\Inbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Warehouse\Models\Location;

class Inbound extends Model
{
    use HasUuid7;
    const TYPE_PURCHASE_ORDER = 'PURCHASE_ORDER';
    const TYPE_SALES_RETURN   = 'SALES_RETURN';
    const TYPE_TRANSIT_IN     = 'TRANSIT_IN';
    const TYPE_CONSIGNMENT    = 'CONSIGNMENT';

    const TYPES = [
        self::TYPE_PURCHASE_ORDER,
        self::TYPE_SALES_RETURN,
        self::TYPE_TRANSIT_IN,
        self::TYPE_CONSIGNMENT,
    ];

    const STATUS_DRAFT                = 'DRAFT';
    const STATUS_PARTIAL              = 'PARTIAL';
    const STATUS_RECEIVED             = 'RECEIVED';
    const STATUS_PUTAWAY_IN_PROGRESS  = 'PUTAWAY_IN_PROGRESS';
    const STATUS_COMPLETED            = 'COMPLETED';
    const STATUS_CANCELLED            = 'CANCELLED';

    protected $fillable = [
        'location_id',
        'transaction_number',
        'reference_number',
        'type',
        'source_type',
        'source_id',
        'status',
        'expected_date',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'expected_date' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InboundItem::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InboundAssignment::class);
    }

    public function putaways(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Putaway::class, 'source_id')->where('source_type', 'INBOUND');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByLocation($query, string $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PARTIAL]);
    }

    public function isPutawayable(): bool
    {
        return in_array($this->status, [self::STATUS_PARTIAL, self::STATUS_RECEIVED, self::STATUS_PUTAWAY_IN_PROGRESS]);
    }
}
