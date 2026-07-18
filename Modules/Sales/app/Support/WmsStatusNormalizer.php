<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Enums\WmsStatus;

final class WmsStatusNormalizer
{
    private const SHOPEE = [
        'UNPAID'             => WmsStatus::CREATED,
        'READY_TO_SHIP'      => WmsStatus::PAID,
        'PROCESSED'          => WmsStatus::PROCESS,
        'SHIPPED'            => WmsStatus::SHIPPED,
        'CANCELLED'          => WmsStatus::CANCELLED,
        'IN_CANCEL'          => WmsStatus::CANCELLED,
        'COMPLETED'          => WmsStatus::COMPLETED,
        'TO_CONFIRM_RECEIVE' => WmsStatus::SHIPPED,
        'TO_RETURN'          => WmsStatus::RETURNED,
    ];

    private const TIKTOK = [
        'UNPAID'              => WmsStatus::CREATED,
        'AWAITING_SHIPMENT'   => WmsStatus::PAID,
        'AWAITING_COLLECTION' => WmsStatus::READY_TO_SHIP,
        'IN_TRANSIT'          => WmsStatus::SHIPPED,
        'DELIVERED'           => WmsStatus::SHIPPED,
        'COMPLETED'           => WmsStatus::COMPLETED,
        'CANCELLED'           => WmsStatus::CANCELLED,
        'CANCELED'            => WmsStatus::CANCELLED,
    ];

    private const LAZADA = [
        'pending'       => WmsStatus::CREATED,
        'unpaid'        => WmsStatus::CREATED,
        'ready_to_ship' => WmsStatus::PAID,
        'packed'        => WmsStatus::PROCESS,
        'shipped'       => WmsStatus::SHIPPED,
        'delivered'     => WmsStatus::SHIPPED,
        'completed'     => WmsStatus::COMPLETED,
        'canceled'      => WmsStatus::CANCELLED,
        'cancelled'     => WmsStatus::CANCELLED,
        'failed'        => WmsStatus::FAILED,
        'returned'      => WmsStatus::RETURNED,
    ];

    private const WOOCOMMERCE = [
        'pending'    => WmsStatus::CREATED,
        'on-hold'    => WmsStatus::CREATED,
        'processing' => WmsStatus::PROCESS,
        'completed'  => WmsStatus::COMPLETED,
        'cancelled'  => WmsStatus::CANCELLED,
        'refunded'   => WmsStatus::RETURNED,
        'failed'     => WmsStatus::FAILED,
        'shipped'    => WmsStatus::SHIPPED,
    ];

    public static function normalize(?string $channel, ?string $rawCode): ?WmsStatus
    {
        if ($rawCode === null || $rawCode === '') {
            return null;
        }

        $canonical = WmsStatus::tryFrom($rawCode);
        if ($canonical !== null) {
            return $canonical;
        }

        $map = match (strtolower((string) $channel)) {
            'shopee'      => self::SHOPEE,
            'tiktok'      => self::TIKTOK,
            'lazada'      => self::LAZADA,
            'woocommerce' => self::WOOCOMMERCE,
            default       => [],
        };

        if (isset($map[$rawCode])) {
            return $map[$rawCode];
        }

        $upper = strtoupper($rawCode);
        foreach ($map as $key => $value) {
            if (strtoupper((string) $key) === $upper) {
                return $value;
            }
        }

        Log::warning('WmsStatusNormalizer: kode tidak dikenal', [
            'channel'  => $channel,
            'raw_code' => $rawCode,
        ]);

        return WmsStatus::OTHER;
    }
}
