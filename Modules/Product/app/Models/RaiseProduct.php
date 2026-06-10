<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Channel\Models\ChannelShop;

class RaiseProduct extends Model
{
    use HasUuid7;

    protected $fillable = [
        'channel_shop_id',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(RaiseProductDetail::class);
    }
}
