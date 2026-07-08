<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class BinTransferReceiptItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'bin_transfer_receipt_id',
        'bin_transfer_item_id',
        'destination_bin_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BinTransferReceipt::class, 'bin_transfer_receipt_id');
    }

    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(BinTransferItem::class, 'bin_transfer_item_id');
    }

    public function destinationBin(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\LocationBin::class, 'destination_bin_id');
    }
}
