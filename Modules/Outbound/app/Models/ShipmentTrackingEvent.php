<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentTrackingEvent extends Model
{
    const SOURCE_SHOPEE = 'shopee';
    const SOURCE_TIKTOK = 'tiktok';
    const SOURCE_LAZADA = 'lazada';
    const SOURCE_MANUAL = 'manual';

    const EVENT_DRIVER_ASSIGNED = 'driver_assigned';
    const EVENT_DRIVER_ARRIVED = 'driver_arrived';
    const EVENT_PICKED_UP = 'picked_up';
    const EVENT_IN_TRANSIT = 'in_transit';
    const EVENT_DELIVERED = 'delivered';
    const EVENT_FAILED = 'failed';

    protected $fillable = [
        'shipment_id',
        'source',
        'event_type',
        'driver_name',
        'driver_phone',
        'driver_vehicle_plate',
        'raw_payload',
        'occurred_at',
        'received_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
