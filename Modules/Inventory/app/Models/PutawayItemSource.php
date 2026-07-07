<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PutawayItemSource extends Model
{
    use HasUuid7;

    protected $fillable = [
        'putaway_item_id',
        'inbound_item_id',
        'qty',
        'putaway_qty',
    ];

    public function putawayItem(): BelongsTo
    {
        return $this->belongsTo(PutawayItem::class);
    }

    public function inboundItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inbound\Models\InboundItem::class, 'inbound_item_id');
    }
}
