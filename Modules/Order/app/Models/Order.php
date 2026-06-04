<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'salesorder_no',
        'channel_shop_id',
        'customer_name',
        'transaction_date',
        'sub_total',
        'total_disc',
        'total_tax',
        'shipping_cost',
        'insurance_cost',
        'grand_total',
        'shipping_full_name',
        'shipping_phone',
        'shipping_address',
        'shipping_area',
        'shipping_city',
        'shipping_province',
        'shipping_post_code',
        'shipping_country',
        'status',
        'is_paid',
        'is_canceled',
        'cancel_reason',
        'channel_status',
        'payment_method',
        'source',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'is_paid' => 'boolean',
        'is_canceled' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
