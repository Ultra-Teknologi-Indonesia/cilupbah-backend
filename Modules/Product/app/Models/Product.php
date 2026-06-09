<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuid7;
    use SoftDeletes;

    // ==================== State Machine ====================

    public const STATUS_DOWNLOAD = 'download';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_MASTER = 'master';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DOWNLOAD,
        self::STATUS_IN_REVIEW,
        self::STATUS_MASTER,
        self::STATUS_ARCHIVED,
    ];

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
        'status',
        'verified_at',
        'verified_by',
        'archived_at',
        'archived_by',
        'archive_reason',
        'is_bundle',
        'is_consignment',
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
        'verified_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_bundle' => 'boolean',
        'is_consignment' => 'boolean',
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

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'archived_by');
    }

    /** Overlay merge: menempelkan produk ini ke satu master_name. */
    public function merge(): HasOne
    {
        return $this->hasOne(ProductMerge::class);
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

    /**
     * Scope: Filter produk berdasarkan status lifecycle.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
