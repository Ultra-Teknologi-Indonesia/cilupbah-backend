<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductChannelMapping;

class ChannelStockSyncOutbox extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHING = 'dispatching';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'channel_stock_sync_outbox';

    protected $fillable = [
        'product_channel_mapping_id',
        'product_id',
        'channel_shop_id',
        'sync_stock',
        'sync_price',
        'queue_tier',
        'status',
        'requested_version',
        'dispatched_version',
        'completed_version',
        'attempt_count',
        'next_attempt_at',
        'lease_expires_at',
        'dispatched_at',
        'completed_at',
        'last_error',
    ];

    protected $casts = [
        'sync_stock' => 'boolean',
        'sync_price' => 'boolean',
        'requested_version' => 'integer',
        'dispatched_version' => 'integer',
        'completed_version' => 'integer',
        'attempt_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ProductChannelMapping::class, 'product_channel_mapping_id');
    }

    public function action(): string
    {
        if ($this->sync_stock && $this->sync_price) {
            return 'sync_price_stock';
        }

        return $this->sync_price ? 'sync_price' : 'sync_stock';
    }
}
