<?php

namespace Modules\Warehouse\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocationBin extends Model
{
    use HasFactory, HasUuid7;

    protected static function newFactory()
    {
        return \Modules\Warehouse\Database\Factories\LocationBinFactory::new();
    }

    protected $fillable = [
        'location_id',
        'zone_id',
        'floor_code',
        'row_code',
        'column_code',
        'bin_code',
        'bin_final_code',
        'max_qty',
        'is_inbound',
        'is_stock_acknowledged',
        'is_large_bin',
        'category',
    ];

    protected $casts = [
        'is_inbound' => 'boolean',
        'is_stock_acknowledged' => 'boolean',
        'is_large_bin' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(LocationZone::class, 'zone_id');
    }
}
