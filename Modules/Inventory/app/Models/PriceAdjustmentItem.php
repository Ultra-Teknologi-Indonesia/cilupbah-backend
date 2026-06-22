<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;

class PriceAdjustmentItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'price_adjustment_id',
        'variant_id',
        'channel_shop_id',
        'location_id',
        'old_price',
        'new_price',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PriceAdjustment::class, 'price_adjustment_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
