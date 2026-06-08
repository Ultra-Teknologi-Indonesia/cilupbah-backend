<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Channel\Models\ChannelShop;

class ProductChannelDraft extends Model
{
    use HasUuid7;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'product_id',
        'channel_shop_id',
        'channel_category_id',
        'attribute_mapping',
        'price_override',
        'status',
        'created_by',
    ];

    protected $casts = [
        'attribute_mapping' => 'array',
        'price_override' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }
}
