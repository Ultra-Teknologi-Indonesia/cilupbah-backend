<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasUuid7;

    protected $table = 'payment_methods';

    protected $fillable = [
        'code',
        'name',
        'source_channel',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
