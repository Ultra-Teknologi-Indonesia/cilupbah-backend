<?php

namespace Modules\Channel\Services;

/**
 * Single source of truth for seller-facing cancellation reasons per marketplace.
 *
 * Each marketplace uses its own distinct reason keys/enums (the `key` is what
 * must be sent back to that marketplace's cancel API), so the catalogs are kept
 * separate and intentionally do not share values.
 *
 * Lazada additionally exposes a live, shop-scoped reason list via
 * LazadaOrderService::getCancelReasons(); this static catalog is the curated
 * default used by the unified API and for offline/validation purposes.
 */
class MarketplaceCancelReasonService
{
    public const TIKTOK = 'tiktok';
    public const LAZADA = 'lazada';
    public const SHOPEE = 'shopee';

    public const SUPPORTED = [self::TIKTOK, self::LAZADA, self::SHOPEE];

    /**
     * @var array<string, array<int, array{key: string, label: string}>>
     */
    private const CATALOG = [
        self::TIKTOK => [
            ['key' => 'seller_cancel_reason_out_of_stock', 'label' => 'Stok habis'],
            ['key' => 'seller_cancel_reason_wrong_price', 'label' => 'Kesalahan harga'],
            ['key' => 'seller_cancel_paid_reason_address_not_deliver', 'label' => 'Alamat pembeli tidak terjangkau'],
        ],
        self::LAZADA => [
            ['key' => 'out_of_stock', 'label' => 'Stok habis'],
            ['key' => 'pricing_error', 'label' => 'Kesalahan harga'],
            ['key' => 'duplicate_order', 'label' => 'Pesanan ganda'],
            ['key' => 'system_error', 'label' => 'Kesalahan sistem'],
            ['key' => 'customer_request', 'label' => 'Permintaan pembeli'],
        ],
        self::SHOPEE => [
            ['key' => 'OUT_OF_STOCK', 'label' => 'Stok habis'],
            ['key' => 'CUSTOMER_REQUEST', 'label' => 'Permintaan pembeli'],
            ['key' => 'UNDELIVERABLE_AREA', 'label' => 'Area tidak terjangkau'],
            ['key' => 'COD_NOT_SUPPORTED', 'label' => 'COD tidak didukung'],
            ['key' => 'OTHERS', 'label' => 'Lainnya'],
        ],
    ];

    public function supports(string $marketplace): bool
    {
        return in_array(strtolower($marketplace), self::SUPPORTED, true);
    }

    /**
     * Cancellation reasons for one marketplace.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function for(string $marketplace): array
    {
        return self::CATALOG[strtolower($marketplace)] ?? [];
    }

    /**
     * Cancellation reasons for every supported marketplace, keyed by marketplace.
     *
     * @return array<string, array<int, array{key: string, label: string}>>
     */
    public function all(): array
    {
        return self::CATALOG;
    }

    /**
     * Valid reason keys for a marketplace (handy for request validation).
     *
     * @return array<int, string>
     */
    public function keys(string $marketplace): array
    {
        return array_column($this->for($marketplace), 'key');
    }

    public function isValidReason(string $marketplace, string $key): bool
    {
        return in_array($key, $this->keys($marketplace), true);
    }
}
