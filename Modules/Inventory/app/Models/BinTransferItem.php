<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'placed_qty',
        'batch_no',
        'serial_no',
        'expired_date',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
        'placed_qty' => 'integer',
        'expired_date' => 'date',
    ];

    protected $appends = ['remaining_qty'];

    protected function remainingQty(): Attribute
    {
        return Attribute::get(fn () => max(0, (int) $this->qty - (int) $this->placed_qty));
    }

    public function binTransfer(): BelongsTo
    {
        return $this->belongsTo(BinTransfer::class);
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(BinTransferReceiptItem::class);
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
