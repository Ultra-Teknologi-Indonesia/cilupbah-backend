<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

/**
 * @property \Illuminate\Database\Eloquent\Collection $orders
 */
class Shipment extends Model
{
    use HasUuid7;

    const STATUS_SCHEDULED = 'SCHEDULED';
    const STATUS_HANDED_OVER = 'HANDED_OVER';
    const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'shipment_no',
        'location_id',
        'courier_name',
        'courier_code',
        'shipment_type',
        'shipment_date',
        'status',
        'handed_over_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'handed_over_at' => 'datetime',
        'has_instant' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(ShipmentOrder::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
