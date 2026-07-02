<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SalesReturnSetting extends Model
{
    use HasUuid7;

    public const DEFAULT_CONDITIONS = ['GOOD', 'DAMAGE'];
    public const DEFAULT_REFUND_METHODS = ['cash', 'tunai', 'transfer', 'bank', 'bank_transfer', 'giro', 'store_credit'];

    protected $fillable = [
        'auto_accept',
        'auto_receive',
        'default_restock_location_id',
        'allowed_conditions',
        'allowed_refund_methods',
        'return_validity_days',
    ];

    protected $casts = [
        'auto_accept' => 'boolean',
        'auto_receive' => 'boolean',
        'allowed_conditions' => 'array',
        'allowed_refund_methods' => 'array',
        'return_validity_days' => 'integer',
    ];
}
