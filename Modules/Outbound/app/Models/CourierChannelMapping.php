<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class CourierChannelMapping extends Model
{
    use HasUuid7;

    protected $fillable = [
        'channel_code',
        'external_name',
        'external_id',
        'courier_id',
        'shipment_type',
        'is_verified',
        'tenant_id',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
