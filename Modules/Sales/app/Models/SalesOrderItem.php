<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;

use App\Traits\HasUuid7;

class SalesOrderItem extends Model
{
    use HasUuid7;

    protected $table = 'sales_order_items';

    protected $fillable = [
        'order_id',
        'item_id',
        'channel_product_id',
        'sku',
        'description',
        'qty_in_base',
        'price',
        'disc',
        'disc_amount',
        'tax_amount',
        'amount',
        'shipper',
        'tracking_no',
    ];

    protected $appends = ['image_url'];

    public function order(): BelongsTo
    {

        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class, 'item_id', 'item_id');
    }

    public function channelMapping(): BelongsTo
    {
        return $this->belongsTo(ProductChannelMapping::class, 'channel_product_id', 'external_product_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        $variant = $this->relationLoaded('product') ? $this->product : null;
        if ($variant) {
            if ($variant->relationLoaded('media')) {
                $url = $this->resolveMediaUrl($variant->media);
                if ($url) {
                    return $url;
                }
            }

            $parentProduct = $variant->relationLoaded('product') ? $variant->product : null;
            if ($parentProduct && $parentProduct->relationLoaded('media')) {
                $url = $this->resolveMediaUrl($parentProduct->media);
                if ($url) {
                    return $url;
                }
            }
        }

        if ($this->channel_product_id && $this->relationLoaded('channelMapping')) {
            $mapping = $this->channelMapping;
            if ($mapping && $mapping->relationLoaded('product') && $mapping->product && $mapping->product->relationLoaded('media')) {
                return $this->resolveMediaUrl($mapping->product->media);
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
