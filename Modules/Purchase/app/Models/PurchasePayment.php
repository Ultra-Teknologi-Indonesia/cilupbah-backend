<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class PurchasePayment extends Model
{
    use HasUuid7;

    protected $fillable = [
        'payment_number',
        'purchase_bill_id',
        'amount',
        'payment_date',
        'payment_method',
        'payment_method_id',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\PaymentMethod::class, 'payment_method_id');
    }
}
