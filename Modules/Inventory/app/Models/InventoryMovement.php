<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class InventoryMovement extends Model
{
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'location_id',
        'bin_id',
        'inbound_receipt_id',
        'transaction_number',
        'source',
        'qty',
        'balance',
        'cost_per_unit',
        'total_cost',
        'transaction_date',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'cost_per_unit' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }
}
