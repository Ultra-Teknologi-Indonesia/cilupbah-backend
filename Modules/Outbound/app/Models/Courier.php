<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;

class Courier extends Model
{
    use HasUuid7;

    protected $fillable = [
        'name',
        'code',
        'type',
        'tracking_url',
        'logo_url',
        'is_active',
        'supported_shipment_types',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supported_shipment_types' => 'array',
        'metadata' => 'array',
    ];
}
