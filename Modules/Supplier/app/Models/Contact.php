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
        'mobile',
        'fax',
        'address',
        'city',
        'province',
        'postal_code',
        'shipping_address',
        'shipping_province',
        'shipping_postal_code',
        'shipping_same_as_billing',
        'latitude',
        'longitude',
        'tax_id',
        'nik',
        'contact_person',
        'pic_title',
        'payment_term',
        'notes',
        'status',
        'type',
        'category_id',
        'is_system',
        'is_company',
        'account_payable',
    ];

    protected $casts = [
        'is_system'              => 'boolean',
        'is_company'             => 'boolean',
        'shipping_same_as_billing' => 'boolean',
        'payment_term'           => 'integer',
        'latitude'               => 'decimal:7',
        'longitude'              => 'decimal:7',
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
