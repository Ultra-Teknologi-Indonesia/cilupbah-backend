<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{
    protected $fillable = [
        'name',
        'type', // 'sales' or 'spec'
    ];
}
