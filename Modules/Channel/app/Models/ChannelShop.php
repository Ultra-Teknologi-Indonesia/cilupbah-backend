<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Warehouse\Models\Location;

use App\Traits\HasUuid7;

class ChannelShop extends Model
{
    use HasUuid7;
    protected $fillable = [
        'channel_id',
        'shop_id',
        'shop_name',
        'store_url',
        'consumer_key',
        'consumer_secret',
        'webhook_secret',
        'external_webhook_ids',
        'shop_cipher',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_expires_at',
        'is_active',
        'order_sync_enabled',
        'is_shadow_mode',
        'shadow_started_at',
        'shadow_last_pulled_at',
        'stock_push_enabled',
        'stock_push_buffer',
        'stock_handover_at',
        'catalog_pull_enabled',
        'catalog_push_enabled',
        'disconnected_at',
        'integration_status',
        'last_error',
        'last_synced_at',
        'order_sync_status',
        'last_order_synced_at',
        'last_order_error',
        'last_order_error_at',
        'stock_source_mode',
        'stock_source_location_id',
    ];

    public const ORDER_SYNC_NORMAL = 'normal';
    public const ORDER_SYNC_PROBLEM = 'problem';
    public const ORDER_SYNC_PENDING = 'pending';
    public const ORDER_SYNC_INACTIVE = 'nonaktif';

    protected $casts = [
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'order_sync_enabled' => 'boolean',
        'is_shadow_mode' => 'boolean',
        'shadow_started_at' => 'datetime',
        'shadow_last_pulled_at' => 'datetime',
        'stock_push_enabled' => 'boolean',
        'stock_push_buffer' => 'integer',
        'stock_handover_at' => 'datetime',
        'catalog_pull_enabled' => 'boolean',
        'catalog_push_enabled' => 'boolean',
        'disconnected_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_order_synced_at' => 'datetime',
        'last_order_error_at' => 'datetime',
        'consumer_key' => 'encrypted',
        'consumer_secret' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'external_webhook_ids' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'shop_cipher',
        'consumer_key',
        'consumer_secret',
        'webhook_secret',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(ProductChannelMapping::class);
    }

    public function stockSourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'stock_source_location_id');
    }
}
