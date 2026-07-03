<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class BinTransferItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'bin_transfer_id',
        'item_id',
        'source_bin_id',
        'destination_bin_id',
        'qty',
        'batch_no',
        'serial_no',
        'expired_date',
        'notes',
    ];

    protected $casts = [
        'expired_date' => 'date',
    ];

    public function binTransfer(): BelongsTo
    {
        return $this->belongsTo(BinTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\Modules\Product\Models\ProductVariant::class, 'item_id');
    }

    public function sourceBin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'source_bin_id');
    }

    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'destination_bin_id');
    }
}
