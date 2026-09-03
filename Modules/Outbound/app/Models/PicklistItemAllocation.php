<?php

namespace Modules\Outbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\LocationBin;

class PicklistItemAllocation extends Model
{
    use HasUuid7;

    protected $table = 'picklist_item_allocations';

    protected $fillable = [
        'picklist_item_id',
        'bin_id',
        'qty',
        'physical_committed_qty',
        'picked_at',
        'picked_by',
        'movement_id',
    ];

    protected $casts = [
        'picked_at' => 'datetime',
        'qty' => 'integer',
        'physical_committed_qty' => 'integer',
    ];

    public function picklistItem(): BelongsTo
    {
        return $this->belongsTo(PicklistItem::class, 'picklist_item_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }
}
