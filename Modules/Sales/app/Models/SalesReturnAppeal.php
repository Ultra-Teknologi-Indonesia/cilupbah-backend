<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class SalesReturnAppeal extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sales_return_id',
        'record_type',
        'operator',
        'description',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    const OPERATOR_BUYER    = 'BUYER';
    const OPERATOR_SELLER   = 'SELLER';
    const OPERATOR_PLATFORM = 'PLATFORM';

    const RECORD_TYPE_CREATED         = 'CREATED';
    const RECORD_TYPE_SELLER_APPROVED = 'SELLER_APPROVED';
    const RECORD_TYPE_SELLER_REJECTED = 'SELLER_REJECTED';
    const RECORD_TYPE_BUYER_SHIPPED   = 'BUYER_SHIPPED';
    const RECORD_TYPE_PLATFORM_DECISION = 'PLATFORM_DECISION';
    const RECORD_TYPE_REFUND_ISSUED   = 'REFUND_ISSUED';
    const RECORD_TYPE_CLOSED          = 'CLOSED';

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }
}
