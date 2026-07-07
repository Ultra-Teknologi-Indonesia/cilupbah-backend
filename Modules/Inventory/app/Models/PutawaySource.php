<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PutawaySource extends Model
{
    use HasUuid7;

    protected $fillable = [
        'putaway_id',
        'inbound_id',
    ];

    public function putaway(): BelongsTo
    {
        return $this->belongsTo(Putaway::class);
    }

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inbound\Models\Inbound::class, 'inbound_id');
    }
}
