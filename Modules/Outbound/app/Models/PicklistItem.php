<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class PicklistItem extends Model
{
    use HasUuid7;

    const STATUS_PENDING = 'PENDING';
    const STATUS_PARTIAL = 'PARTIAL';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_SHORT = 'SHORT';
    const STATUS_REJECTED = 'REJECTED';

    const REASON_STOCK_EMPTY = 'STOCK_EMPTY';
    const REASON_DAMAGED = 'DAMAGED';
    const REASON_REJECTED = 'REJECTED';
    const REASON_MISSING = 'MISSING';
    const REASON_OTHER = 'OTHER';

    protected $fillable = [
        'picklist_id',
        'order_id',
        'order_item_id',
        'item_id',
        'sku',
        'bin_id',
        'qty_ordered',
        'qty_picked',
        'item_status',
        'fail_reason_code',
        'fail_reason_note',
        'failed_qty',
        'failed_at',
        'failed_by',
    ];

    protected $casts = [
        'failed_at' => 'datetime',
        'failed_qty' => 'integer',
    ];

    protected $appends = ['image_url', 'effective_item_status'];

    public function picklist(): BelongsTo
    {
        return $this->belongsTo(Picklist::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PicklistItemAllocation::class, 'picklist_item_id');
    }

    public function getEffectiveItemStatusAttribute(): string
    {
        if (!empty($this->attributes['item_status'])) {
            return $this->attributes['item_status'];
        }

        $picked = (int) ($this->qty_picked ?? 0);
        $ordered = (int) ($this->qty_ordered ?? 0);

        if ($picked <= 0) {
            return self::STATUS_PENDING;
        }

        if ($picked >= $ordered) {
            return self::STATUS_COMPLETED;
        }

        return self::STATUS_PARTIAL;
    }

    public function getImageUrlAttribute(): ?string
    {
        $variant = $this->relationLoaded('product') ? $this->product : null;
        if ($variant) {
            $url = $this->resolveMediaUrl($variant->relationLoaded('media') ? $variant->media : null);
            if ($url) {
                return $url;
            }

            $parentProduct = $variant->relationLoaded('product') ? $variant->product : null;
            if ($parentProduct) {
                $url = $this->resolveMediaUrl(
                    $parentProduct->relationLoaded('media') ? $parentProduct->media : null
                );
                if ($url) {
                    return $url;
                }
            }
        }

        return null;
    }

    protected function resolveMediaUrl($media): ?string
    {
        if (! $media || $media->isEmpty()) {
            return null;
        }

        $primary = $media->firstWhere('is_primary', true);

        return $primary ? $primary->url : $media->first()->url;
    }
}
