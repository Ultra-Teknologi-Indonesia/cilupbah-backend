<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\ProductVariant;

class InboundItem extends Model
{
    protected $fillable = [
        'inbound_id',
        'item_id',
        'expected_qty',
        'received_qty',
        'putaway_qty',
        'discrepancy_qty',
        'discrepancy_note',
        'condition',
    ];

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }

    public function isFullyReceived(): bool
    {
        return $this->received_qty >= $this->expected_qty;
    }

    public function isFullyPutaway(): bool
    {
        return $this->putaway_qty >= $this->received_qty;
    }

    public function pendingPutawayQty(): int
    {
        return max(0, $this->received_qty - $this->putaway_qty);
    }
}
