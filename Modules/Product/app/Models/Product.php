<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuid7;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'sku',
        'description',
        'search_keyword',
        'order_type',
        'indent_days',
        'weight',
        'length',
        'width',
        'height',
        'condition',
        'is_cod_allowed',
        'danger_level',
        'is_draft',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_draft' => 'boolean',
        'is_cod_allowed' => 'boolean',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
    ];

    // ==================== Relasi ====================

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function channelMappings(): HasMany
    {
        return $this->hasMany(ProductChannelMapping::class);
    }

    // ==================== Scopes ====================

    /**
     * Scope: Filter produk berdasarkan role name.
     * Digunakan oleh Spatie Query Builder → AllowedFilter::scope('channel')
     */
    public function scopeChannel($query, string $channelShopId)
    {
        return $query->whereHas('channelMappings', function ($q) use ($channelShopId) {
            $q->where('channel_shop_id', $channelShopId);
        });
    }
}
