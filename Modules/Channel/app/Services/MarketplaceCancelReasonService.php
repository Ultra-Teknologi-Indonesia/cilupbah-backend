<?php

namespace Modules\Channel\Services;

class MarketplaceCancelReasonService
{
    public const TIKTOK = 'tiktok';
    public const LAZADA = 'lazada';
    public const SHOPEE = 'shopee';

    public const SUPPORTED = [self::TIKTOK, self::LAZADA, self::SHOPEE];

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

    public function for(string $marketplace): array
    {
        return self::CATALOG[strtolower($marketplace)] ?? [];
    }

    public function all(): array
    {
        return self::CATALOG;
    }

    public function keys(string $marketplace): array
    {
        return array_column($this->for($marketplace), 'key');
    }

    public function isValidReason(string $marketplace, string $key): bool
    {
        return in_array($key, $this->keys($marketplace), true);
    }
}
