<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Traits\HasUuid7;

class SalesOrder extends Model
{
    use HasUuid7;

    protected $table = 'sales_orders';

    protected $fillable = [
        'salesorder_no',
        'channel_order_no',
        'so_sequence',
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
        'cancel_request_reason',
        'cancel_requested_at',
        'cancel_requested_by',
        'channel_status',
        'channel_fulfillment_status',
        'fulfillment_type',
        'delivery_option_id',
        'shipping_type',
        'payment_method',
        'payment_method_name',
        'tracking_number',
        'shipping_provider',
        'buyer_message',
        'seller_note',
        'paid_time',
        'source',
        'location_id',
        'received_date',
    ];

    protected $casts = [
        'transaction_date'    => 'datetime',
        'paid_time'           => 'datetime',
        'cancel_requested_at' => 'datetime',
        'received_date'       => 'datetime',
        'is_paid'             => 'boolean',
        'is_canceled'         => 'boolean',
    ];

    public function items(): HasMany
    {

        return $this->hasMany(SalesOrderItem::class, 'order_id');
    }

    public function picklistItems(): HasMany
    {
        return $this->hasMany(\Modules\Outbound\Models\PicklistItem::class, 'order_id');
    }

    public function packlist(): HasOne
    {
        return $this->hasOne(\Modules\Outbound\Models\Packlist::class, 'order_id');
    }

    public function shipmentOrders(): HasMany
    {
        return $this->hasMany(\Modules\Outbound\Models\ShipmentOrder::class, 'order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'order_id');
    }
}
