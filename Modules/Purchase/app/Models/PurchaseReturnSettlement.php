<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class PurchaseReturnSettlement extends Model
{
    use HasUuid7;

    protected $fillable = [
        'settlement_number',
        'return_id',
        'status',
        'total_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    const STATUS_DRAFT     = 'DRAFT';
    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_COMPLETED = 'COMPLETED';

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseReturnSettlementBill::class, 'settlement_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PurchaseReturnSettlementRefund::class, 'settlement_id');
    }
}
