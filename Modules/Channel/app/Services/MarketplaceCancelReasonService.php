<?php

namespace Modules\Channel\Services;

class MarketplaceCancelReasonService
{
    public const TIKTOK = 'tiktok';
    public const LAZADA = 'lazada';
    public const SHOPEE = 'shopee';

    public const SUPPORTED = [self::TIKTOK, self::LAZADA, self::SHOPEE];

    public const LIVE_ONLY = [self::LAZADA];

    private const TIKTOK_UNPAID = [
        ['key' => 'seller_cancel_unpaid_reason_out_of_stock', 'label' => 'Stok habis'],
        ['key' => 'seller_cancel_unpaid_reason_wrong_price', 'label' => 'Kesalahan harga'],
        ['key' => 'seller_cancel_unpaid_reason_buyer_hasnt_paid_within_time_allowed', 'label' => 'Pembeli belum membayar tepat waktu'],
    ];

    private const TIKTOK_PAID = [
        ['key' => 'seller_cancel_reason_out_of_stock', 'label' => 'Stok habis'],
        ['key' => 'seller_cancel_reason_wrong_price', 'label' => 'Kesalahan harga'],
        ['key' => 'seller_cancel_paid_reason_address_not_deliver', 'label' => 'Alamat pembeli tidak terjangkau'],
    ];

    // Shopee: CUSTOMER_REQUEST ditolak API untuk seller-cancel ("cancel reason is
    // invalid") — hanya reason seller ini yang diterima. UNDELIVERABLE_AREA = TW/MY only.
    private const SHOPEE_REASONS = [
        ['key' => 'OUT_OF_STOCK', 'label' => 'Stok habis'],
        ['key' => 'COD_NOT_SUPPORTED', 'label' => 'COD tidak didukung'],
    ];

    public function supports(string $marketplace): bool
    {
        return in_array(strtolower($marketplace), self::SUPPORTED, true);
    }

    public function isLiveOnly(string $marketplace): bool
    {
        return in_array(strtolower($marketplace), self::LIVE_ONLY, true);
    }

    public function tiktokStatusGroup(?string $rawStatus): string
    {
        return strtoupper((string) $rawStatus) === 'UNPAID' ? 'unpaid' : 'paid';
    }

    public function for(string $marketplace, ?string $context = null): array
    {
        return match (strtolower($marketplace)) {
            self::TIKTOK => $this->tiktokStatusGroup($context) === 'unpaid'
                ? self::TIKTOK_UNPAID
                : self::TIKTOK_PAID,
            self::SHOPEE => self::SHOPEE_REASONS,
            self::LAZADA => [], 
            default      => [],
        };
    }

    public function all(): array
    {
        return [
            self::TIKTOK => ['unpaid' => self::TIKTOK_UNPAID, 'paid' => self::TIKTOK_PAID],
            self::SHOPEE => self::SHOPEE_REASONS,
            self::LAZADA => [], 
        ];
    }

    public function keys(string $marketplace, ?string $context = null): array
    {
        return array_column($this->for($marketplace, $context), 'key');
    }

    public function isValidReason(string $marketplace, string $key, ?string $context = null): bool
    {
        if ($this->isLiveOnly($marketplace)) {
            return false;
        }

        return in_array($key, $this->keys($marketplace, $context), true);
    }

    public function normalize(array $raw): array
    {
        $out = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['key'] ?? $item['reason_key'] ?? $item['reason_id'] ?? $item['code'] ?? $item['id'] ?? null;
            $label = $item['label'] ?? $item['name'] ?? $item['reason_name'] ?? $item['text'] ?? $item['reason'] ?? $item['message'] ?? null;

            if ($key === null && $label === null) {
                continue;
            }

            $out[] = [
                'key'   => (string) ($key ?? $label),
                'label' => (string) ($label ?? $key),
            ];
        }

        return $out;
    }
}
