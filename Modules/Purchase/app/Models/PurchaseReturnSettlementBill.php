<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchaseReturnSettlementBill extends Model
{
    use HasUuid7;

    protected $fillable = [
        'settlement_id',
        'bill_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnSettlement::class, 'settlement_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'bill_id');
    }
}
