<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PicklistItemAllocation extends Model
{
    use HasUuid7;

    protected $table = 'picklist_item_allocations';

    protected $fillable = [
        'picklist_item_id',
        'bin_id',
        'qty',
        'picked_at',
        'picked_by',
        'movement_id',
    ];

    protected $casts = [
        'picked_at' => 'datetime',
        'qty' => 'integer',
    ];

    public function picklistItem(): BelongsTo
    {
        return $this->belongsTo(PicklistItem::class, 'picklist_item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }
}
