<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuid7;
    use SoftDeletes;

    public const STATUS_DOWNLOAD = 'download';

    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_MASTER = 'master';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DOWNLOAD,
        self::STATUS_MASTER,
        self::STATUS_ARCHIVED,
    ];

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
        'status',
        'verified_at',
        'verified_by',
        'archived_at',
        'archived_by',
        'archive_reason',
        'is_bundle',
        'is_consignment',
        'is_stored',
        'is_sold',
        'is_purchased',
        'purchase_lead_time',
        'package_contents',
        'sales_account_id',
        'sales_return_account_id',
        'inventory_account_id',
        'cogs_account_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_draft' => 'boolean',
        'is_cod_allowed' => 'boolean',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'verified_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_bundle' => 'boolean',
        'is_consignment' => 'boolean',
        'is_stored' => 'boolean',
        'is_sold' => 'boolean',
        'is_purchased' => 'boolean',
        'purchase_lead_time' => 'integer',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventories(): HasManyThrough
    {
        return $this->hasManyThrough(
            \Modules\Inventory\Models\Inventory::class,
            ProductVariant::class,
            'product_id',
            'item_id',
        );
    }

    public function variationTypes(): HasMany
    {
        return $this->hasMany(ProductVariationType::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_product_id');
    }

    public function channelMappings(): HasMany
    {
        return $this->hasMany(ProductChannelMapping::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(\Modules\Finance\Models\Account::class, 'sales_account_id');
    }

    public function salesReturnAccount(): BelongsTo
    {
        return $this->belongsTo(\Modules\Finance\Models\Account::class, 'sales_return_account_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(\Modules\Finance\Models\Account::class, 'inventory_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(\Modules\Finance\Models\Account::class, 'cogs_account_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'archived_by');
    }

    public function merge(): HasOne
    {
        return $this->hasOne(ProductMerge::class);
    }

    public function scopeChannel($query, string $channelShopId)
    {
        return $query->whereHas('channelMappings', function ($q) use ($channelShopId) {
            $q->where('channel_shop_id', $channelShopId);
        });
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
