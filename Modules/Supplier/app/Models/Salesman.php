<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;

class Salesman extends Model
{
    use HasUuid7;

    protected $table = 'salesmen';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'status',
        'notes',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
}
