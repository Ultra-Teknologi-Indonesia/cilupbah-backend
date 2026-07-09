<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property \Illuminate\Database\Eloquent\Collection $orders
 */
class Shipment extends Model implements HasMedia
{
    use HasUuid7, InteractsWithMedia;

    const STATUS_SCHEDULED = 'SCHEDULED';
    const STATUS_HANDED_OVER = 'HANDED_OVER';
    const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_CANCELLED = 'CANCELLED';

    const DRIVER_CALL_METHOD_MANUAL = 'MANUAL';
    const DRIVER_CALL_METHOD_SHOPEE_AUTO = 'SHOPEE_AUTO';

    const DRIVER_STATUS_NONE = 'NONE';
    const DRIVER_STATUS_CALLED = 'CALLED';
    const DRIVER_STATUS_PICKED_UP = 'PICKED_UP';
    const DRIVER_STATUS_FAILED = 'FAILED';

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
        'driver_name',
        'driver_phone',
        'driver_vehicle_plate',
        'driver_booking_code',
        'driver_call_method',
        'driver_call_status',
        'driver_called_at',
        'driver_called_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'handed_over_at' => 'datetime',
        'driver_called_at' => 'datetime',
        'has_instant' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('driver_id_card')
            ->singleFile()
            ->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(256)
            ->height(256)
            ->nonQueued();
    }

    protected $appends = ['driver_id_card_url'];

    public function getDriverIdCardUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('driver_id_card') ?: null;
    }

    public function getDriverIdCardThumbAttribute(): ?string
    {
        return $this->getFirstMediaUrl('driver_id_card', 'thumb') ?: null;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShipmentOrder::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
