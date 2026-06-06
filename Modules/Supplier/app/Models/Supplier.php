<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'code',
        'name',
        'company_name',
        'email',
        'phone',
        'address',
        'city',
        'tax_id',
        'contact_person',
        'payment_term',
        'notes',
        'status',
    ];
}
