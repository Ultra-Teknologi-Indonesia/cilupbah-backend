<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Traits\HasUuid7;
use Modules\Outbound\Support\InstantOrderClassifier;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SalesOrder extends Model implements HasMedia
{
    use HasUuid7, InteractsWithMedia;

    protected $table = 'sales_orders';

    protected $appends = ['is_instant'];

    protected $fillable = [
        'salesorder_no',
        'channel_order_no',
        'so_sequence',
        'channel_shop_id',
        'channel_buyer_id',
        'customer_name',
        'transaction_date',
        'sub_total',
        'total_disc',
        'seller_voucher',
        'platform_voucher',
        'payment_voucher',
        'commission_fee',
        'service_fee',
        'transaction_fee',
        'affiliate_commission',
        'order_processing_fee',
        'seller_shipping_borne',
        'platform_shipping_rebate',
        'settlement_amount',
        'fee_currency',
        'is_settled',
        'finance_synced_at',
        'total_tax',
        'shipping_cost',
        'actual_shipping_fee',
        'actual_shipping_fee_confirmed',
        'insurance_cost',
        'grand_total',
        'order_weight_gram',
        'shipping_full_name',
        'shipping_phone',
        'shipping_address',
        'shipping_area',
        'shipping_city',
        'shipping_province',
        'shipping_post_code',
        'shipping_country',
        'shipping_coordinate',
        'dropshipper_name',
        'dropshipper_phone',
        'courier_name',
        'courier_phone',
        'pickup_code',
        'courier_pickup_recorded_at',
        'courier_pickup_recorded_by',
        'status',
        'is_paid',
        'is_canceled',
        'is_cod',
        'priority_fulfillment',
        'is_split_order',
        'cancel_reason',
        'cancel_by',
        'cancel_request_reason',
        'cancel_requested_at',
        'cancel_requested_by',
        'cancel_accepted_at',
        'cancel_accepted_by',
        'cancel_channel',
        'cancel_rejected_at',
        'cancel_rejected_by',
        'cancel_reject_reason',
        'cancel_dismissed_at',
        'cancel_dismissed_by',
        'pick_failed_at',
        'pick_failed_by',
        'pick_fail_reason',
        'contacted_at',
        'contacted_by',
        'contact_channel',
        'customer_decision',
        'decision_at',
        'decision_by',
        'contact_note',
        'channel_status',
        'channel_fulfillment_status',
        'fulfillment_flag',
        'fulfillment_type',
        'delivery_option_id',
        'shipping_type',
        'days_to_ship',
        'payment_method',
        'payment_method_name',
        'tracking_number',
        'shipping_provider',
        'shipping_label_status',
        'shipping_label_doc_type',
        'shipping_label_prepared_at',
        'shipping_label_raw_data',
        'driver_call_status',
        'driver_call_message',
        'driver_call_attempted_at',
        'driver_call_response',
        'buyer_message',
        'seller_note',
        'paid_time',
        'ship_by_date',
        'pickup_done_time',
        'channel_updated_at',
        'return_due_date',
        'source',
        'location_id',
        'received_date',
        'handed_to_warehouse_at',
        'internal_store_id',
        'salesman_id',
        'is_manual',
        'no_ref',
        'note',
        'delivery_method',
        'is_jubelio_shipment',
        'shipping_discount',
        'other_discount',
        'price_includes_tax',
    ];

    protected $casts = [
        'transaction_date'    => 'datetime',
        'paid_time'           => 'datetime',
        'ship_by_date'        => 'datetime',
        'pickup_done_time'    => 'datetime',
        'channel_updated_at'  => 'datetime',
        'return_due_date'     => 'datetime',
        'cancel_requested_at' => 'datetime',
        'pick_failed_at'      => 'datetime',
        'cancel_accepted_at'  => 'datetime',
        'cancel_rejected_at'  => 'datetime',
        'cancel_dismissed_at' => 'datetime',
        'contacted_at'        => 'datetime',
        'decision_at'         => 'datetime',
        'received_date'       => 'datetime',
        'handed_to_warehouse_at' => 'datetime',
        'courier_pickup_recorded_at' => 'datetime',
        'shipping_label_prepared_at' => 'datetime',
        'shipping_label_raw_data'    => 'array',
        'driver_call_attempted_at'   => 'datetime',
        'driver_call_response'       => 'array',
        'finance_synced_at'   => 'datetime',
        'is_settled'                    => 'boolean',
        'is_paid'                       => 'boolean',
        'is_canceled'                   => 'boolean',
        'is_cod'                        => 'boolean',
        'priority_fulfillment'          => 'boolean',
        'is_split_order'                => 'boolean',
        'actual_shipping_fee_confirmed' => 'boolean',
        'is_manual'                     => 'boolean',
        'is_jubelio_shipment'           => 'boolean',
        'price_includes_tax'            => 'boolean',
    ];

    public const DELIVERY_COURIER          = 'COURIER';
    public const DELIVERY_SELF_PICKUP      = 'SELF_PICKUP';
    public const DELIVERY_JUBELIO_SHIPMENT = 'JUBELIO_SHIPMENT';

    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    public function scopeWhereDateFrom($query, $date)
    {
        return $query->whereDate('transaction_date', '>=', $date);
    }

    public function scopeWhereDateTo($query, $date)
    {
        return $query->whereDate('transaction_date', '<=', $date);
    }

    public function isManual(): bool
    {
        return (bool) $this->is_manual;
    }

    public function internalStore(): BelongsTo
    {
        return $this->belongsTo(InternalStore::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(\Modules\Supplier\Models\Salesman::class);
    }

    public function items(): HasMany
    {

        return $this->hasMany(SalesOrderItem::class, 'order_id');
    }

    /**
     * Sumber tunggal kondisi item "stok kosong" (SQL, level sales_order_items):
     * qty item melebihi stok tersedia (on_hand - reserved) di gudang order.
     *
     * Item bundle DIKECUALIKAN: variant bundle tidak punya inventory sendiri
     * (stok ada di komponen, dicek saat picking via cascadeBundle), jadi kalau
     * tidak dikecualikan setiap order bundle akan salah terdeteksi stok kosong.
     *
     * Dipakai bersama oleh scopeHasStockShortfall (guard proses) & tab
     * empty-stock/ready-to-process di SalesOrderRepository supaya konsisten.
     */
    public static function shortfallItemWhereRaw(): string
    {
        return "sales_order_items.qty_in_base > COALESCE((
                    SELECT GREATEST(0, COALESCE(SUM(on_hand),0) - COALESCE(SUM(reserved),0))
                    FROM inventories
                    WHERE inventories.item_id = sales_order_items.item_id
                      AND inventories.location_id = sales_orders.location_id
                ), 0)
                AND NOT EXISTS (
                    SELECT 1 FROM product_variants pv
                    JOIN products p ON p.id = pv.product_id
                    WHERE pv.id = sales_order_items.item_id
                      AND p.is_bundle = true
                )";
    }

    /**
     * Order punya minimal satu item stok kosong (bukan bundle). Dipakai oleh
     * guard proses (Sales + Outbound) agar konsisten dengan tab empty-stock.
     */
    public function scopeHasStockShortfall($query)
    {
        return $query->whereHas('items', fn ($q) => $q->whereRaw(static::shortfallItemWhereRaw()));
    }

    public function feeLines(): HasMany
    {
        return $this->hasMany(SalesOrderFeeLine::class, 'order_id');
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

    public function shop(): BelongsTo
    {
        return $this->belongsTo(\Modules\Channel\Models\ChannelShop::class, 'channel_shop_id', 'shop_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'order_id');
    }

    public function getIsInstantAttribute(): bool
    {
        return InstantOrderClassifier::isInstant(
            $this->shipping_provider,
            $this->shipping_type,
        );
    }

    public function registerMediaCollections(): void
    {
        // Foto identitas kurir (bukti pickup). 1 foto per pesanan (Fase 1).
        $this->addMediaCollection('courier_id')
            ->singleFile()
            ->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(256)
            ->height(256)
            ->nonQueued();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesOrderStatusHistory::class, 'salesorder_id')->orderBy('created_at');
    }
}
