<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchaseReturnSettlementRefund extends Model
{
    use HasUuid7;

    protected $fillable = [
        'settlement_id',
        'refund_number',
        'amount',
        'refund_method',
        'refund_date',
        'notes',
    ];

    protected $casts = [
        'refund_date' => 'date',
        'amount'      => 'decimal:2',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnSettlement::class, 'settlement_id');
    }
}
