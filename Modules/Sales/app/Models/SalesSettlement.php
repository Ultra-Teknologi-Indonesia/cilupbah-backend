<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;

class SalesSettlement extends Model
{
    use HasUuid7;

    protected $fillable = [
        'settlement_number',
        'channel',
        'period_start',
        'period_end',
        'total_sales',
        'total_fee',
        'total_settlement',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'total_sales'      => 'decimal:2',
        'total_fee'        => 'decimal:2',
        'total_settlement' => 'decimal:2',
    ];

    const STATUS_DRAFT      = 'DRAFT';
    const STATUS_CONFIRMED  = 'CONFIRMED';
    const STATUS_RECONCILED = 'RECONCILED';
}
