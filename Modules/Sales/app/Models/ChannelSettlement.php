<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelSettlement extends Model
{
    use HasUuid7;

    protected $fillable = [
        'channel',
        'shop_id',
        'external_id',
        'external_payment_id',
        'type',
        'period_start',
        'period_end',
        'settled_at',
        'paid_at',
        'payment_status',
        'total_settlement',
        'total_fee',
        'total_adjustment',
        'currency',
        'raw',
        'synced_at',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'settled_at'       => 'datetime',
        'paid_at'          => 'datetime',
        'synced_at'        => 'datetime',
        'total_settlement' => 'decimal:4',
        'total_fee'        => 'decimal:4',
        'total_adjustment' => 'decimal:4',
        'raw'              => 'array',
    ];

    public function adjustments(): HasMany
    {
        return $this->hasMany(ChannelSettlementAdjustment::class, 'channel_settlement_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'channel_settlement_id');
    }
}
