<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelShop extends Model
{
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
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
