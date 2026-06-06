<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocationBin extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return \Modules\Warehouse\Database\Factories\LocationBinFactory::new();
    }

    protected $fillable = [
        'location_id',
        'floor_code',
        'row_code',
        'column_code',
        'bin_code',
        'bin_final_code',
        'max_qty',
        'is_inbound',
    ];

    protected $casts = [
        'is_inbound' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
