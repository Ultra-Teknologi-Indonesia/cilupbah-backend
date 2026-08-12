<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Inventory\Support\StockSummary;
use Modules\Sales\Support\ChannelStatusNormalizer;
use Modules\Outbound\Support\InstantOrderClassifier;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SalesOrder extends Model implements HasMedia
{
    use HasUuid7, InteractsWithMedia, HasFactory;

    protected static function newFactory(): \Modules\Sales\Database\Factories\SalesOrderFactory
    {
        return \Modules\Sales\Database\Factories\SalesOrderFactory::new();
    }

    protected $table = 'sales_orders';

    public const SEARCH_COLUMNS = [
        'salesorder_no',
        'channel_order_no',
        'customer_name',
        'tracking_number',
    ];

    public static function qualifiedSearchColumns(): array
    {
        return array_map(fn (string $column) => 'sales_orders.'.$column, self::SEARCH_COLUMNS);
    }

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
        'other_fee',
        'seller_shipping_borne',
        'platform_shipping_rebate',
        'settlement_amount',
        'refund_total',
        'gross_amount',
        'fee_currency',
        'is_settled',
        'finance_synced_at',
        'settled_at',
        'channel_settlement_id',
        'finance_raw',
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
        'courier_id',
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
        'channel_cancel_requested_at',
        'channel_cancel_requested_by',
        'channel_cancel_status',
        'channel_cancel_error',
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
        'channel_status_raw',
        'channel_fulfillment_status',
        'fulfillment_flag',
        'fulfillment_type',
        'delivery_option_id',
        'shipping_type',
        'resolved_shipment_type',
        'days_to_ship',
        'payment_method',
        'payment_method_name',
        'payment_method_id',
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
        'is_shadow',
        'no_ref',
        'note',
        'delivery_method',
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
        'channel_cancel_requested_at' => 'datetime',
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
        'settled_at'          => 'datetime',
        'finance_raw'         => 'array',
        'is_settled'                    => 'boolean',
        'is_paid'                       => 'boolean',
        'is_canceled'                   => 'boolean',
        'is_cod'                        => 'boolean',
        'priority_fulfillment'          => 'boolean',
        'is_split_order'                => 'boolean',
        'actual_shipping_fee_confirmed' => 'boolean',
        'is_manual'                     => 'boolean',
        'is_shadow'                     => 'boolean',
        'price_includes_tax'            => 'boolean',
        'contact_channel'   => \Modules\Sales\Enums\ContactChannel::class,
        'customer_decision' => \Modules\Sales\Enums\CustomerDecision::class,
    ];

    public const DELIVERY_COURIER          = 'COURIER';
    public const DELIVERY_SELF_PICKUP      = 'SELF_PICKUP';

    protected function channelStatus(): Attribute
    {
        return Attribute::make(
            set: function ($value, array $attributes) {
                if ($value === null || $value === '') {
                    return null;
                }
                $channel = $attributes['source'] ?? $this->attributes['source'] ?? null;
                $normalized = ChannelStatusNormalizer::normalize($channel, (string) $value);
                return $normalized?->value ?? \Modules\Sales\Enums\ChannelStatus::UNKNOWN->value;
            },
        );
    }

    protected function wmsStatus(): Attribute
    {
        return Attribute::make(
            set: function ($value, array $attributes) {
                if ($value === null || $value === '') {
                    return null;
                }
                $channel = $attributes['source'] ?? $this->attributes['source'] ?? null;
                $normalized = \Modules\Sales\Support\WmsStatusNormalizer::normalize($channel, (string) $value);
                return $normalized?->value ?? \Modules\Sales\Enums\WmsStatus::OTHER->value;
            },
        );
    }

    public function statusEnum(): ?\Modules\Sales\Enums\SalesOrderStatus
    {
        return $this->status ? \Modules\Sales\Enums\SalesOrderStatus::tryFrom($this->status) : null;
    }

    public function wmsStatusEnum(): ?\Modules\Sales\Enums\WmsStatus
    {
        return $this->wms_status ? \Modules\Sales\Enums\WmsStatus::tryFrom($this->wms_status) : null;
    }

    public function channelStatusEnum(): ?\Modules\Sales\Enums\ChannelStatus
    {
        return $this->channel_status
            ? (\Modules\Sales\Enums\ChannelStatus::tryFrom($this->channel_status)
                ?? \Modules\Sales\Enums\ChannelStatus::UNKNOWN)
            : null;
    }

    public function channelEnum(): ?\Modules\Sales\Enums\SalesOrderChannel
    {
        return $this->source ? \Modules\Sales\Enums\SalesOrderChannel::tryFrom($this->source) : null;
    }

    public function disputeOutcomeEnum(): ?\Modules\Sales\Enums\DisputeOutcome
    {
        return $this->dispute_outcome ? \Modules\Sales\Enums\DisputeOutcome::tryFrom($this->dispute_outcome) : null;
    }

    public function cancelReasonEnum(): ?\Modules\Sales\Enums\SalesCancelReason
    {
        return $this->cancel_reason ? \Modules\Sales\Enums\SalesCancelReason::tryFrom($this->cancel_reason) : null;
    }

    public function driverCallStatusEnum(): ?\Modules\Sales\Enums\DriverCallStatus
    {
        return $this->driver_call_status
            ? \Modules\Sales\Enums\DriverCallStatus::tryFrom($this->driver_call_status)
            : null;
    }

    public function cancelChannelEnum(): ?\Modules\Sales\Enums\CancelChannel
    {
        return $this->cancel_channel
            ? \Modules\Sales\Enums\CancelChannel::tryFrom($this->cancel_channel)
            : null;
    }

    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    public function scopeExcludeShadow($query)
    {
        return $query->where(function ($q) {
            $q->where('is_shadow', false)->orWhereNull('is_shadow');
        });
    }

    public function scopeOnlyShadow($query)
    {
        return $query->where('is_shadow', true);
    }

    public function scopeWhereDateFrom($query, $date)
    {
        return $query->whereDate('transaction_date', '>=', $date);
    }

    public function scopeWhereDateTo($query, $date)
    {
        return $query->whereDate('transaction_date', '<=', $date);
    }

    public function scopeWhereSettledFrom($query, $date)
    {
        return $query->whereDate('settled_at', '>=', $date);
    }

    public function scopeWhereSettledTo($query, $date)
    {
        return $query->whereDate('settled_at', '<=', $date);
    }

    public function isManual(): bool
    {
        return (bool) $this->is_manual;
    }

    public function itemsFullyDownloaded(): bool
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->isNotEmpty()
            && ! $items->contains(fn ($item) => empty($item->item_id));
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

    public function courier(): BelongsTo
    {
        return $this->belongsTo(\Modules\Outbound\Models\Courier::class, 'courier_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public static function shortfallItemWhereRaw(): string
    {

        $available = StockSummary::availableSql('inventories', 'location_bins');

        return "sales_order_items.qty_in_base > COALESCE((
                    SELECT {$available}
                    FROM inventories
                    LEFT JOIN location_bins ON location_bins.id = inventories.bin_id
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

    public function completedPicklists(): BelongsToMany
    {
        return $this->belongsToMany(\Modules\Outbound\Models\Picklist::class, 'picklist_items', 'order_id', 'picklist_id')
            ->where('picklists.status', \Modules\Outbound\Models\Picklist::STATUS_COMPLETED);
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
