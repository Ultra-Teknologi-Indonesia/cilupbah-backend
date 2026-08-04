<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelSettlementAdjustment extends Model
{
    use HasUuid7;

    protected $fillable = [
        'channel_settlement_id',
        'channel',
        'shop_id',
        'external_transaction_id',
        'type',
        'order_id',
        'channel_order_no',
        'amount',
        'reason',
        'occurred_at',
        'currency',
        'raw',
    ];

    protected $casts = [
        'amount'      => 'decimal:4',
        'occurred_at' => 'datetime',
        'raw'         => 'array',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ChannelSettlement::class, 'channel_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }
}
