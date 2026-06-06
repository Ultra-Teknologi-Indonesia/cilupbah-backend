<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Location extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Modules\Warehouse\Database\Factories\LocationFactory::new();
    }

    protected $fillable = [
        'location_code',
        'location_name',
        'location_type',
        'address',
        'area',
        'city',
        'province',
        'post_code',
        'is_warehouse',
        'is_multi_origin',
        'default_warehouse_user',
        'is_active',
        'is_fbl',
        'is_tcb',
        'is_fbs',
    ];

    protected $casts = [
        'is_warehouse' => 'boolean',
        'is_multi_origin' => 'boolean',
        'is_active' => 'boolean',
        'is_fbl' => 'boolean',
        'is_tcb' => 'boolean',
        'is_fbs' => 'boolean',
    ];

    public function channelWarehouses(): HasMany
    {
        return $this->hasMany(ChannelWarehouse::class);
    }

    public function bins(): HasMany
    {
        return $this->hasMany(LocationBin::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class);
    }
}
