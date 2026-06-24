<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Traits\HasUuid7;

class SalesOrderFeeLine extends Model
{
    use HasUuid7;

    protected $table = 'sales_order_fee_lines';

    protected $fillable = [
        'order_id',
        'fee_type',
        'channel_fee_code',
        'amount',
        'source',
        'is_settled',
    ];

    protected $casts = [
        'is_settled' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }
}
