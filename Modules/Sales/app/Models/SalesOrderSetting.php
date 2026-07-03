<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SalesOrderSetting extends Model
{
    use HasUuid7;

    protected $fillable = [
        'auto_accept_cancel_on_packed',
    ];

    protected $casts = [
        'auto_accept_cancel_on_packed' => 'boolean',
    ];
}
