<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\DisputeOutcomeNormalizer;
use Modules\Warehouse\Models\Location;
use App\Traits\HasUuid7;

class SalesReturn extends Model
{
    use HasUuid7;

    protected $fillable = [
        'return_number',
        'order_id',
        'location_id',
        'source',
        'channel_return_id',
        'channel_shop_id',
        'return_tracking_number',
        'return_carrier',
        'return_shipped_at',
        'tracking_synced_at',
        'customer_name',
        'customer_contact',
        'status',
        'reason',
        'reason_category',
        'notes',
        'created_by',
        'processed_by',
        'processed_at',
        'marketplace_decision',
        'marketplace_decision_at',
        'marketplace_raw_status',
        'channel_reason_code',
        'channel_reason_text',
        'refund_amount',
        'refund_currency',
        'shipping_fee_original',
        'shipping_fee_return',
        'detail_synced_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'return_shipped_at' => 'datetime',
        'tracking_synced_at' => 'datetime',
        'marketplace_decision_at' => 'datetime',
        'detail_synced_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'shipping_fee_original' => 'decimal:2',
        'shipping_fee_return' => 'decimal:2',
    ];

    protected $appends = [
        'channel',
        'channel_name',
        'channel_shop_name',
        'reason_display',
        'marketplace_decision_label',
        'marketplace_raw_status_label',
    ];

    protected function marketplaceDecision(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }

                $value = strtoupper(trim((string) $value));

                if (in_array($value, self::MP_DECISIONS, true)) {
                    return $value;
                }

                $legacyMap = [
                    'PENDING' => self::MP_DECISION_PENDING,
                    'SELLER_WIN' => self::MP_DECISION_REJECTED,
                    'BUYER_WIN' => self::MP_DECISION_APPROVED,
                    'NO_RETURN_NEEDED' => self::MP_DECISION_NOT_RETURN,
                    'SELLER_REFUSE_RETURN' => self::MP_DECISION_REJECTED,
                    'REFUNDED' => self::MP_DECISION_REFUNDED,
                    'CANCELLED' => self::MP_DECISION_CLOSED,
                ];

                if (isset($legacyMap[$value])) {
                    return $legacyMap[$value];
                }

                $canonical = \Modules\Sales\Enums\DisputeOutcome::tryFrom((string) $value);
                if ($canonical !== null) {
                    return $legacyMap[$canonical->value] ?? self::MP_DECISION_PENDING;
                }
                $channel = $this->relationLoaded('order')
                    ? $this->order?->source
                    : ($this->attributes['source'] ?? null);
                $normalized = DisputeOutcomeNormalizer::normalize($channel, (string) $value);
                return $normalized
                    ? ($legacyMap[$normalized->value] ?? self::MP_DECISION_PENDING)
                    : self::MP_DECISION_PENDING;
            },
        );
    }

    protected function channel(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    return strtolower((string) $value);
                }

                $channelReturnId = (string) ($this->attributes['channel_return_id'] ?? '');
                if (str_contains($channelReturnId, ':')) {
                    return strtolower((string) str($channelReturnId)->before(':'));
                }

                $order = $this->relationLoaded('order') ? $this->order : null;
                return $order?->source ? strtolower((string) $order->source) : null;
            },
        );
    }

    protected function channelName(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    return (string) $value;
                }

                return [
                    'tiktok' => 'TikTok Shop',
                    'shopee' => 'Shopee',
                    'lazada' => 'Lazada',
                    'woocommerce' => 'WooCommerce',
                    'tokopedia' => 'Tokopedia',
                    'blibli' => 'Blibli',
                ][$this->channel ?? ''] ?? ucfirst((string) ($this->channel ?? 'Manual'));
            },
        );
    }

    protected function channelShopName(): Attribute
    {
        return Attribute::make(get: fn ($value) => $value ?: null);
    }

    protected function reasonDisplay(): Attribute
    {
        return Attribute::make(
            get: function () {
                $text = trim((string) ($this->attributes['channel_reason_text'] ?? ''));
                $code = strtoupper(trim((string) ($this->attributes['channel_reason_code'] ?? '')));
                $reason = trim((string) ($this->attributes['reason'] ?? ''));
                $labels = [
                    'wrong product sent' => 'Produk yang dikirim tidak sesuai',
                    'change of mind' => 'Pembeli berubah pikiran',
                    'product doesn\'t match description' => 'Produk tidak sesuai deskripsi',
                    'product does not match description' => 'Produk tidak sesuai deskripsi',
                    'WRONG_PRODUCT' => 'Produk yang dikirim tidak sesuai',
                    'NO_NEED' => 'Pembeli berubah pikiran',
                    'NO_NEED_NON_MALL' => 'Pembeli berubah pikiran',
                    'NOT_MATCH_DESCRIPTION' => 'Produk tidak sesuai deskripsi',
                    'RETURN_OR_REFUND_REQUEST_SUCCESS' => 'Permintaan retur/refund berhasil',
                ];

                foreach ([$text, $code, $reason] as $candidate) {
                    if ($candidate === '') {
                        continue;
                    }
                    if (isset($labels[$candidate])) {
                        return $labels[$candidate];
                    }
                    if (isset($labels[strtolower($candidate)])) {
                        return $labels[strtolower($candidate)];
                    }
                }

                $fallback = $text !== '' ? $text : ($reason !== '' ? $reason : $code);
                return $fallback === '' ? null : self::humanizeCode($fallback);
            },
        );
    }

    protected function marketplaceDecisionLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $decision = strtoupper(trim((string) ($this->attributes['marketplace_decision'] ?? '')));
                return self::MP_DECISION_LABELS[$decision] ?? [
                    'PENDING' => 'Menunggu Keputusan',
                    'REFUNDED' => 'Dana Dikembalikan',
                    'BUYER_WIN' => 'Disetujui Marketplace',
                    'SELLER_WIN' => 'Ditolak Marketplace',
                ][$decision] ?? ($decision !== '' ? self::humanizeCode($decision) : null);
            },
        );
    }

    protected function marketplaceRawStatusLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $status = strtoupper(trim((string) ($this->attributes['marketplace_raw_status'] ?? '')));
                if ($status === '') {
                    return null;
                }

                return [
                    'RETURN_OR_REFUND_REQUEST_PENDING' => 'Menunggu peninjauan channel',
                    'AWAITING_BUYER_SHIP' => 'Menunggu barang dikirim pembeli',
                    'BUYER_SHIPPED_ITEM' => 'Barang dikirim pembeli',
                    'REQUEST_SUCCESS' => 'Permintaan disetujui channel',
                    'REQUEST_REJECTED' => 'Permintaan ditolak channel',
                    'RETURN_OR_REFUND_REQUEST_REJECT' => 'Permintaan retur/refund ditolak channel',
                    'REFUND_OR_RETURN_REQUEST_REJECT' => 'Permintaan refund/retur ditolak channel',
                    'RECEIVE_REJECTED' => 'Penerimaan ditolak channel',
                    'REJECT_RECEIVE_PACKAGE' => 'Penerimaan paket ditolak channel',
                    'RETURN_OR_REFUND_REQUEST_COMPLETE' => 'Retur/refund selesai di channel',
                    'RETURN_OR_REFUND_CANCEL' => 'Retur/refund dibatalkan di channel',
                    'RETURN_OR_REFUND_REQUEST_CANCEL' => 'Permintaan retur/refund dibatalkan di channel',
                    'REPLACEMENT_REQUEST_CANCEL' => 'Permintaan penggantian dibatalkan di channel',
                    'REPLACEMENT_REQUEST_REJECT' => 'Permintaan penggantian ditolak channel',
                    'REQUESTED' => 'Menunggu keputusan channel',
                    'ACCEPTED' => 'Disetujui channel',
                    'PROCESSING' => 'Sedang diproses channel',
                    'CLOSED' => 'Ditutup channel',
                    'CANCELLED' => 'Dibatalkan channel',
                    'REFUNDED' => 'Dana dikembalikan channel',
                    'COMPLETED' => 'Selesai di channel',
                ][$status] ?? self::humanizeCode($status);
            },
        );
    }

    private static function humanizeCode(string $value): string
    {
        return ucfirst(strtolower(trim(str_replace(['_', '-'], ' ', $value))));
    }

    const SOURCE_MANUAL      = 'manual';
    const SOURCE_MARKETPLACE = 'marketplace';

    const STATUS_PENDING   = 'PENDING';
    const STATUS_ACCEPTED  = 'ACCEPTED';
    const STATUS_REJECTED  = 'REJECTED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELLED = 'CANCELLED';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    const REASON_CATEGORY_FAILED_DELIVERY = 'FAILED_DELIVERY';
    const REASON_CATEGORY_COMPLAINT       = 'COMPLAINT';
    const REASON_CATEGORY_CANCEL_SHIPPED  = 'CANCEL_SHIPPED';
    const REASON_CATEGORY_REMORSE         = 'REMORSE';
    const REASON_CATEGORY_OTHER           = 'OTHER';

    const REASON_CATEGORIES = [
        self::REASON_CATEGORY_FAILED_DELIVERY,
        self::REASON_CATEGORY_COMPLAINT,
        self::REASON_CATEGORY_CANCEL_SHIPPED,
        self::REASON_CATEGORY_REMORSE,
        self::REASON_CATEGORY_OTHER,
    ];

    const REASON_CATEGORY_LABELS = [
        self::REASON_CATEGORY_FAILED_DELIVERY => 'Gagal Kirim',
        self::REASON_CATEGORY_COMPLAINT       => 'Komplain Pembeli',
        self::REASON_CATEGORY_CANCEL_SHIPPED  => 'Cancel Telanjur Kirim',
        self::REASON_CATEGORY_REMORSE         => 'Berubah Pikiran',
        self::REASON_CATEGORY_OTHER           => 'Lainnya',
    ];

    const MP_DECISION_PENDING    = 'MP_PENDING';
    const MP_DECISION_APPROVED   = 'MP_APPROVED';
    const MP_DECISION_REJECTED   = 'MP_REJECTED';
    const MP_DECISION_DISPUTE    = 'MP_DISPUTE';
    const MP_DECISION_JUDGING    = 'MP_JUDGING';
    const MP_DECISION_REFUNDED   = 'MP_REFUNDED';
    const MP_DECISION_CLOSED     = 'MP_CLOSED';
    const MP_DECISION_NOT_RETURN = 'MP_NOT_RETURN';

    const MP_DECISION_LABELS = [
        self::MP_DECISION_PENDING    => 'Menunggu Keputusan',
        self::MP_DECISION_APPROVED   => 'Disetujui Marketplace',
        self::MP_DECISION_REJECTED   => 'Ditolak Marketplace',
        self::MP_DECISION_DISPUTE    => 'Dalam Banding',
        self::MP_DECISION_JUDGING    => 'Diarbitrase Marketplace',
        self::MP_DECISION_REFUNDED   => 'Dana Dikembalikan',
        self::MP_DECISION_CLOSED     => 'Ditutup',
        self::MP_DECISION_NOT_RETURN => 'Bukan Retur',
    ];

    const MP_DECISIONS = [
        self::MP_DECISION_PENDING,
        self::MP_DECISION_APPROVED,
        self::MP_DECISION_REJECTED,
        self::MP_DECISION_DISPUTE,
        self::MP_DECISION_JUDGING,
        self::MP_DECISION_REFUNDED,
        self::MP_DECISION_CLOSED,
        self::MP_DECISION_NOT_RETURN,
    ];

    const MP_DECISION_MAP = [

        'shopee' => [
            'PENDING'         => self::MP_DECISION_PENDING,
            'REQUESTED'      => self::MP_DECISION_PENDING,
            'ACCEPTED'       => self::MP_DECISION_APPROVED,
            'PROCESSING'     => self::MP_DECISION_PENDING,
            'SELLER_DISPUTE' => self::MP_DECISION_DISPUTE,
            'JUDGING'        => self::MP_DECISION_JUDGING,
            'CANCELLED'      => self::MP_DECISION_CLOSED,
            'CLOSED'         => self::MP_DECISION_CLOSED,
            'EXPIRED'        => self::MP_DECISION_CLOSED,
            'REFUNDED'       => self::MP_DECISION_REFUNDED,
            'REJECTED'       => self::MP_DECISION_REJECTED,
        ],

        'tiktok' => [
            'PENDING'                             => self::MP_DECISION_PENDING,
            'RETURN_OR_REFUND_REQUEST_PENDING'  => self::MP_DECISION_PENDING,
            'AWAITING_BUYER_SHIP'               => self::MP_DECISION_APPROVED,
            'BUYER_SHIPPED_ITEM'                => self::MP_DECISION_APPROVED,
            'REQUEST_SUCCESS'                   => self::MP_DECISION_APPROVED,
            'REQUEST_REJECTED'                  => self::MP_DECISION_REJECTED,
            'RETURN_OR_REFUND_REQUEST_REJECT'   => self::MP_DECISION_REJECTED,
            'REFUND_OR_RETURN_REQUEST_REJECT'   => self::MP_DECISION_REJECTED,
            'RECEIVE_REJECTED'                  => self::MP_DECISION_REJECTED,
            'REJECT_RECEIVE_PACKAGE'            => self::MP_DECISION_REJECTED,
            'RETURN_OR_REFUND_REQUEST_COMPLETE' => self::MP_DECISION_REFUNDED,
            'RETURN_OR_REFUND_CANCEL'           => self::MP_DECISION_CLOSED,
            'RETURN_OR_REFUND_REQUEST_CANCEL'   => self::MP_DECISION_CLOSED,
            'REPLACEMENT_REQUEST_CANCEL'        => self::MP_DECISION_CLOSED,
            'REPLACEMENT_REQUEST_REJECT'        => self::MP_DECISION_REJECTED,

            'PENDING_REQUEST_REVIEW'            => self::MP_DECISION_PENDING,
            'REQUEST_REVIEW_COMPLETED'          => self::MP_DECISION_APPROVED,
            'RMA_CREATED'                       => self::MP_DECISION_APPROVED,
            'REFUND_SUCCESS'                    => self::MP_DECISION_REFUNDED,
        ],

        'lazada' => [

            'CANCEL_INIT'          => self::MP_DECISION_PENDING,
            'CANCEL_SUCCESS'       => self::MP_DECISION_REFUNDED,
            'CANCEL_REFUND_ISSUED' => self::MP_DECISION_REFUNDED,

            'RTM_INIT'             => self::MP_DECISION_PENDING,
            'RTM_CANCELED'         => self::MP_DECISION_CLOSED,
            'RTM_SHIPPING_BACK'    => self::MP_DECISION_APPROVED,
            'RTM_RECEIVE_ITEM'     => self::MP_DECISION_APPROVED,

            'RTW_INIT'             => self::MP_DECISION_PENDING,
            'RTW_CANCELED'         => self::MP_DECISION_CLOSED,
            'RTW_SHIPPING_BACK'    => self::MP_DECISION_APPROVED,
            'RTW_REJECT'           => self::MP_DECISION_REJECTED,
            'RTW_REFUND_PENDING'   => self::MP_DECISION_REFUNDED,

            'REFUND_INIT'          => self::MP_DECISION_PENDING,
            'REFUND_PENDING'       => self::MP_DECISION_APPROVED,
            'REFUND_SUCCESS'       => self::MP_DECISION_REFUNDED,
            'REFUND_REJECTED'      => self::MP_DECISION_REJECTED,
        ],

        'woocommerce' => [
            'REFUNDED'   => self::MP_DECISION_REFUNDED,
            'COMPLETED'  => self::MP_DECISION_REFUNDED,
            'PROCESSING' => self::MP_DECISION_APPROVED,
            'ON-HOLD'    => self::MP_DECISION_PENDING,
            'PENDING'    => self::MP_DECISION_PENDING,
            'CANCELLED'  => self::MP_DECISION_CLOSED,
            'FAILED'     => self::MP_DECISION_CLOSED,
        ],
    ];

    public static function normalizeMarketplaceDecision(string $channel, string $rawStatus): string
    {
        $map = self::MP_DECISION_MAP[strtolower(trim($channel))] ?? [];
        $upperStatus = strtoupper(trim($rawStatus));

        if (! isset($map[$upperStatus])) {
            \Illuminate\Support\Facades\Log::warning('Unmapped marketplace return status', [
                'channel'    => $channel,
                'raw_status' => $rawStatus,
            ]);

            return self::MP_DECISION_PENDING;
        }

        return $map[$upperStatus];
    }

    public static function shouldApplyMarketplaceDecision(?string $current, ?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $current = strtoupper(trim((string) $current));
        $candidate = strtoupper(trim($candidate));

        $legacyMap = [
            'PENDING' => self::MP_DECISION_PENDING,
            'BUYER_WIN' => self::MP_DECISION_APPROVED,
            'SELLER_WIN' => self::MP_DECISION_REJECTED,
            'SELLER_REFUSE_RETURN' => self::MP_DECISION_REJECTED,
            'REFUNDED' => self::MP_DECISION_REFUNDED,
            'CANCELLED' => self::MP_DECISION_CLOSED,
            'NO_RETURN_NEEDED' => self::MP_DECISION_NOT_RETURN,
        ];

        $current = $legacyMap[$current] ?? $current;
        $candidate = $legacyMap[$candidate] ?? $candidate;

        if ($current === '' || $current === $candidate) {
            return true;
        }

        $priority = [
            self::MP_DECISION_PENDING => 10,
            self::MP_DECISION_APPROVED => 20,
            self::MP_DECISION_DISPUTE => 30,
            self::MP_DECISION_JUDGING => 30,
            self::MP_DECISION_REJECTED => 40,
            self::MP_DECISION_CLOSED => 40,
            self::MP_DECISION_REFUNDED => 50,
            self::MP_DECISION_NOT_RETURN => 50,
        ];

        return ($priority[$candidate] ?? 0) > ($priority[$current] ?? 0);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('sales_returns.status', $status);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('sales_returns.status', self::STATUS_PENDING);
    }

    public function scopeMarketplace($query)
    {
        return $query->where('sales_returns.source', self::SOURCE_MARKETPLACE);
    }

    public function scopeByReasonCategory($query, string $reasonCategory)
    {
        return $query->where('sales_returns.reason_category', $reasonCategory);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(SalesReturnSettlement::class, 'return_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(SalesReturnAppeal::class, 'sales_return_id')->orderBy('recorded_at');
    }

    public function inbounds(): HasMany
    {
        return $this->hasMany(\Modules\Inbound\Models\Inbound::class, 'source_id')
            ->where('source_type', 'SALES_RETURN');
    }
}
