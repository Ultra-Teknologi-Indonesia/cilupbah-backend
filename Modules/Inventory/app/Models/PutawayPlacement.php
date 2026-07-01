<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PutawayPlacement extends Model
{
    use HasUuid7;

    protected $fillable = [
        'putaway_item_id',
        'bin_id',
        'qty',
    ];

    public function putawayItem(): BelongsTo
    {
        return $this->belongsTo(PutawayItem::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }
}
