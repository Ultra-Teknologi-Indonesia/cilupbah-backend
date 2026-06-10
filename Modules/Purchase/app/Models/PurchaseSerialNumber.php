<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchaseSerialNumber extends Model
{
    use HasUuid7;

    protected $fillable = [
        'purchase_bill_item_id',
        'serial_number',
        'batch_number',
        'expired_date',
        'printed_at',
        'printed_by',
    ];

    protected $casts = [
        'expired_date' => 'date',
        'printed_at'   => 'datetime',
    ];

    public function billItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseBillItem::class, 'purchase_bill_item_id');
    }
}
