<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Channel\Models\Channel;

class ProductChannelValidation extends Model
{
    use HasUuid7;

    public const STATUS_OK = 'ok';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_NA = 'na';

    protected $fillable = [
        'product_id',
        'channel_id',
        'attribute_status',
        'attribute_issues',
        'price_status',
        'price_issues',
        'sku_status',
        'sku_issues',
        'checked_at',
    ];

    protected $casts = [
        'attribute_issues' => 'array',
        'price_issues' => 'array',
        'sku_issues' => 'array',
        'checked_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
