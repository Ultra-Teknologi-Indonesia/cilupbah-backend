<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Traits\HasUuid7;

class ChannelShop extends Model
{
    use HasUuid7;
    protected $fillable = [
        'channel_id',
        'shop_id',
        'shop_name',
        'shop_cipher',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_expires_at',
        'is_active',
        'order_sync_enabled',
        'disconnected_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'order_sync_enabled' => 'boolean',
        'disconnected_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'shop_cipher',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
