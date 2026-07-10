<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class BinTransferReceipt extends Model
{
    use HasUuid7, SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'bin_transfer_id',
        'location_id',
        'received_by',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function binTransfer(): BelongsTo
    {
        return $this->belongsTo(BinTransfer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BinTransferReceiptItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
