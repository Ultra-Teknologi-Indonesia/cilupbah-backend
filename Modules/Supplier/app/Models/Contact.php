<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class Contact extends Model
{
    use HasUuid7;

    protected $fillable = [
        'code',
        'name',
        'company_name',
        'email',
        'phone',
        'address',
        'city',
        'province',
        'postal_code',
        'tax_id',
        'contact_person',
        'payment_term',
        'notes',
        'status',
        'type',
        'category_id',
    ];

    const TYPE_CUSTOMER = 'CUSTOMER';
    const TYPE_SUPPLIER = 'SUPPLIER';
    const TYPE_BOTH     = 'BOTH';

    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContactCategory::class, 'category_id');
    }

    public function scopeCustomers($query)
    {
        return $query->whereIn('type', [self::TYPE_CUSTOMER, self::TYPE_BOTH]);
    }

    public function scopeSuppliers($query)
    {
        return $query->whereIn('type', [self::TYPE_SUPPLIER, self::TYPE_BOTH]);
    }

    public function scopeCustomersAndSuppliers($query)
    {
        return $query->where('type', self::TYPE_BOTH);
    }
}
