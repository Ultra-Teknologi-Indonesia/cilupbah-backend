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
        'phone',
        'email',
        'coordinate',
        'is_warehouse',
        'is_small_warehouse',
        'is_multi_origin',
        'default_warehouse_user',
        'is_active',
        'is_system',
        'is_locked',
        'is_fbl',
        'is_tcb',
        'is_fbs',
        'is_pos',
    ];

    protected $casts = [
        'is_warehouse' => 'boolean',
        'is_small_warehouse' => 'boolean',
        'is_multi_origin' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'is_locked' => 'boolean',
        'is_fbl' => 'boolean',
        'is_tcb' => 'boolean',
        'is_fbs' => 'boolean',
        'is_pos' => 'boolean',
    ];

    public const SYSTEM_TRANSIT_CODE = 'SYS-TRANSIT';
    public const SYSTEM_PUSAT_CODE   = 'WH-PUSAT';
    public const SYSTEM_KECIL_CODE   = 'WH-KECIL';

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

    public function enforcesStrictBinSku(): bool
    {
        return (bool) $this->is_small_warehouse;
    }

    public static function getSmallWarehouseId(): ?string
    {
        return self::query()->where('is_small_warehouse', true)->value('id');
    }

    /**
     * Resolve the single physical source used by channel fulfilment.
     *
     * The code is the stable business identity; the flag remains a
     * backwards-compatible fallback for installations created before the
     * system location code was standardised.
     */
    public static function getOfficialSmallWarehouseId(): ?string
    {
        return self::query()
            ->where('location_code', self::SYSTEM_KECIL_CODE)
            ->value('id')
            ?? self::query()
                ->where('is_small_warehouse', true)
                ->where('is_warehouse', true)
                ->where('is_active', true)
                ->value('id');
    }

    public static function getMainWarehouseId(): ?string
    {
        return self::query()
            ->where('is_warehouse', true)
            ->where('is_small_warehouse', false)
            ->value('id');
    }
}
