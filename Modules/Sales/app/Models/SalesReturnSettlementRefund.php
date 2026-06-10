<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class SalesReturnSettlementRefund extends Model
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
        'amount'      => 'decimal:2',
        'refund_date' => 'date',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SalesReturnSettlement::class, 'settlement_id');
    }
}
