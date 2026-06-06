<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Warehouse\Models\Location;

class Inbound extends Model
{
    protected $fillable = [
        'location_id',
        'transaction_number',
        'reference_number',
        'type',
        'status',
        'expected_date',
        'created_by',
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
}
