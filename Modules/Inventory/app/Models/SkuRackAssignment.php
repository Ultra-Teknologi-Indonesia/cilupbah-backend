<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\LocationBin;

class SkuRackAssignment extends Model
{
    use HasUuid7;

    protected $table = 'sku_rack_assignments';

    protected $fillable = [
        'location_id',
        'item_id',
        'bin_id',
        'assigned_by',
    ];

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }
}
