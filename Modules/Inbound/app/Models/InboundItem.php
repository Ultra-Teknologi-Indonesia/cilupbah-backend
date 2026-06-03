<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Product\Models\Product;

class InboundItem extends Model
{
    protected $fillable = [
        'inbound_id',
        'item_id',
        'expected_qty',
        'received_qty',
        'condition',
    ];

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }

}
