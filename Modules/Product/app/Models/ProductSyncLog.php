<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Channel\Models\ChannelShop;

class ProductSyncLog extends Model
{
    use HasUuid7;

    public const ACTION_UPLOAD = 'upload';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_SYNC_PRICE = 'sync_price';
    public const ACTION_SYNC_STOCK = 'sync_stock';
    public const ACTION_UNLINK = 'unlink';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'product_id',
        'channel_shop_id',
        'action',
        'status',
        'payload',
        'response',
        'error_message',
        'executed_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }

    /**
     * Helper ringkas untuk mencatat satu log sinkronisasi.
     */
    public static function record(array $attributes): self
    {
        return static::create($attributes);
    }
}
