<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class Inventory extends Model
{
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'location_id',
        'bin_id',
        'batch_no',
        'serial_no',
        'expired_date',
        'on_hand',
        'on_order',
        'reserved',
        'available',
        'avg_cost',
    ];

    protected $casts = [
        'expired_date' => 'datetime',
        'avg_cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'bin_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id', 'item_id')
            ->where('location_id', $this->location_id);
    }

    public function recalculateAvailable(): void
    {
        $this->available = max(0, $this->on_hand - (int) $this->on_order - $this->reserved);
    }
}
