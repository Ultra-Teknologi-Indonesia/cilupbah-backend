<?php

namespace Modules\Warehouse\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Database\Factories\LocationBinFactory;

class LocationBin extends Model
{
    use HasFactory, HasUuid7;

    protected static function newFactory()
    {
        return LocationBinFactory::new();
    }

    protected $fillable = [
        'location_id',
        'zone_id',
        'floor_code',
        'row_code',
        'column_code',
        'bin_code',
        'bin_final_code',
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

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'bin_id');
    }

    public function activeInventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'bin_id')
            ->where(function ($q) {
                $q->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            });
    }

    public function productVariants(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductVariant::class,
            Inventory::class,
            'bin_id',
            'id',
            'id',
            'item_id'
        );
    }

    public function assignedProductVariants(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductVariant::class,
            SkuRackAssignment::class,
            'bin_id',
            'id',
            'id',
            'item_id'
        );
    }

    public function skuRackAssignments(): HasMany
    {
        return $this->hasMany(SkuRackAssignment::class, 'bin_id');
    }
}
