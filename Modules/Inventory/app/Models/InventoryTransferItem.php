<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\LocationBin;
use App\Traits\HasUuid7;

class InventoryTransferItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'inventory_transfer_id',
        'item_id',
        'qty',
        'received_qty',
        'source_bin_id',
        'destination_bin_id',
        'batch_no',
        'serial_no',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function sourceBin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'source_bin_id');
    }

    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'destination_bin_id');
    }

    public function isFullyReceived(): bool
    {
        return $this->received_qty >= $this->qty;
    }

    public function pendingQty(): int
    {
        return max(0, $this->qty - $this->received_qty);
    }
}
