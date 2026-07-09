<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sales\Models\SalesOrder;
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

    /**
     * Peta status mentah per channel -> keputusan marketplace ternormalisasi. Dipakai
     * SalesReturnDetailSyncService setiap kali fetchReturnDetail() menarik status baru.
     * Key dicocokkan case-insensitive terhadap raw status dari API channel.
     */
    const MP_DECISION_MAP = [
        'shopee' => [
            'REQUESTED'       => self::MP_DECISION_PENDING,
            'ACCEPTED'        => self::MP_DECISION_APPROVED,
            'DISPUTE'         => self::MP_DECISION_DISPUTE,
            'SELLER_DISPUTE'  => self::MP_DECISION_DISPUTE,
            'JUDGING'         => self::MP_DECISION_JUDGING,
            'REFUND_PAID'     => self::MP_DECISION_REFUNDED,
            'REFUND_SELLER'   => self::MP_DECISION_REFUNDED,
            'PROCESSING'      => self::MP_DECISION_PENDING,
            'CLOSED'          => self::MP_DECISION_CLOSED,
        ],
        'tiktok' => [
            'RETURN_OR_REFUND_REQUEST_INITIATED' => self::MP_DECISION_PENDING,
            'RETURN_OR_REFUND_REQUEST_APPROVE'   => self::MP_DECISION_APPROVED,
            'RETURN_OR_REFUND_REQUEST_REJECT'    => self::MP_DECISION_REJECTED,
            'SELLER_REJECT_RETURN'               => self::MP_DECISION_REJECTED,
            'PLATFORM_INTERVENTION'               => self::MP_DECISION_JUDGING,
            'AWAITING_BUYER_SHIP'                 => self::MP_DECISION_PENDING,
            'BUYER_SHIPPED_ITEM'                  => self::MP_DECISION_PENDING,
            'RETURN_OR_REFUND_REQUEST_COMPLETE'   => self::MP_DECISION_REFUNDED,
            'RETURN_OR_REFUND_REQUEST_CANCEL'     => self::MP_DECISION_CLOSED,
        ],
        'lazada' => [
            'PENDING'    => self::MP_DECISION_PENDING,
            'APPROVED'   => self::MP_DECISION_APPROVED,
            'REJECTED'   => self::MP_DECISION_REJECTED,
            'PROCESSING' => self::MP_DECISION_PENDING,
            'COMPLETED'  => self::MP_DECISION_REFUNDED,
            'CANCELLED'  => self::MP_DECISION_CLOSED,
        ],
    ];

    /**
     * Normalisasi raw status API channel ke keputusan marketplace internal.
     * Status yang tidak dikenal (belum di-mapping / channel API berubah) jatuh ke
     * MP_PENDING agar tidak salah tampil sebagai "selesai" di UI/laporan.
     */
    public static function normalizeMarketplaceDecision(string $channel, string $rawStatus): string
    {
        $map = self::MP_DECISION_MAP[$channel] ?? [];

        return $map[strtoupper($rawStatus)] ?? self::MP_DECISION_PENDING;
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
