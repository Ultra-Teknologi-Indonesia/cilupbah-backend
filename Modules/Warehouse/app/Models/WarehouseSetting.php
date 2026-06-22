<?php

namespace Modules\Warehouse\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class WarehouseSetting extends Model
{
    use HasUuid7;

    protected $fillable = [
        'use_warehouse_layout',
    ];

    protected $casts = [
        'use_warehouse_layout' => 'boolean',
    ];
}
