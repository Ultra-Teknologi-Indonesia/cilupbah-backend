<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Traits\HasUuid7;
use Modules\Region\Models\Village;

class Location extends Model
{
    use HasFactory, HasUuid7;

    protected static function newFactory()
    {
        return \Modules\Warehouse\Database\Factories\LocationFactory::new();
    }

    protected $fillable = [
        'location_code',
        'location_name',
        'location_type',
        'address',
        'village_id',
        'default_bin_id',
        'post_code',
        'is_warehouse',
        'is_multi_origin',
        'default_warehouse_user',
        'is_active',
        'is_fbl',
        'is_tcb',
        'is_fbs',
        'is_pos',
    ];

    protected $casts = [
        'is_warehouse' => 'boolean',
        'is_multi_origin' => 'boolean',
        'is_active' => 'boolean',
        'is_fbl' => 'boolean',
        'is_tcb' => 'boolean',
        'is_fbs' => 'boolean',
        'is_pos' => 'boolean',
    ];

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id', 'id');
    }

    public function channelWarehouses(): HasMany
    {
        return $this->hasMany(ChannelWarehouse::class);
    }

    public function bins(): HasMany
    {
        return $this->hasMany(LocationBin::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(LocationZone::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class);
    }
}
