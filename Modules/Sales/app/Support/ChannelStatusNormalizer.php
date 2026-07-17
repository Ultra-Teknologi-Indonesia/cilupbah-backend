<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Enums\ChannelStatus;

/**
 * Petakan kode `channel_status` mentah per marketplace ke enum kanonik `ChannelStatus`.
 *
 * Prinsip:
 * - Fungsi ini TIDAK PERNAH melempar. Kode tak dikenal → ChannelStatus::UNKNOWN + log warning.
 * - Petakan berdasarkan (channel, rawCode). Kalau channel tak dikenal, pakai UNKNOWN.
 * - Kode yang sudah kanonik (mis. adapter sudah normalisasi sendiri) dilewati langsung.
 */
final class ChannelStatusNormalizer
{
    private const SHOPEE = [
        'UNPAID'              => ChannelStatus::UNPAID,
        'READY_TO_SHIP'       => ChannelStatus::READY_TO_SHIP,
        'PROCESSED'           => ChannelStatus::PROCESSED,
        'RETRY_SHIP'          => ChannelStatus::PROCESSED,
        'SHIPPED'             => ChannelStatus::SHIPPED,
        'IN_CANCEL'           => ChannelStatus::IN_CANCEL,
        'CANCELLED'           => ChannelStatus::CANCELLED,
        'INVOICE_PENDING'     => ChannelStatus::UNPAID,
        'TO_RETURN'           => ChannelStatus::RETURN_REQUESTED,
        'TO_CONFIRM_RECEIVE'  => ChannelStatus::TO_CONFIRM_RECEIVE,
        'COMPLETED'           => ChannelStatus::COMPLETED,
    ];

    private const TIKTOK = [
        'UNPAID'                     => ChannelStatus::UNPAID,
        'ON_HOLD'                    => ChannelStatus::UNPAID,
        'AWAITING_SHIPMENT'          => ChannelStatus::READY_TO_SHIP,
        'PARTIALLY_SHIPPING'         => ChannelStatus::PROCESSED,
        'AWAITING_COLLECTION'        => ChannelStatus::PROCESSED,
        'IN_TRANSIT'                 => ChannelStatus::SHIPPED,
        'DELIVERED'                  => ChannelStatus::TO_CONFIRM_RECEIVE,
        'COMPLETED'                  => ChannelStatus::COMPLETED,
        'CANCELLED'                  => ChannelStatus::CANCELLED,
        'CANCELED'                   => ChannelStatus::CANCELLED,
        'REJECTED'                   => ChannelStatus::CANCELLED,
    ];

    private const LAZADA = [
        'pending'                => ChannelStatus::UNPAID,
        'unpaid'                 => ChannelStatus::UNPAID,
        'ready_to_ship'          => ChannelStatus::READY_TO_SHIP,
        'packed'                 => ChannelStatus::PROCESSED,
        'ready_to_ship_pending'  => ChannelStatus::PROCESSED,
        'shipped'                => ChannelStatus::SHIPPED,
        'in_transit'             => ChannelStatus::SHIPPED,
        'delivered'              => ChannelStatus::TO_CONFIRM_RECEIVE,
        'completed'              => ChannelStatus::COMPLETED,
        'canceled'               => ChannelStatus::CANCELLED,
        'cancelled'              => ChannelStatus::CANCELLED,
        'failed'                 => ChannelStatus::CANCELLED,
        'returned'               => ChannelStatus::RETURNED,
        'topack'                 => ChannelStatus::READY_TO_SHIP,
        'toship'                 => ChannelStatus::PROCESSED,
    ];

    private const WOOCOMMERCE = [
        'pending'    => ChannelStatus::UNPAID,
        'on-hold'    => ChannelStatus::UNPAID,
        'processing' => ChannelStatus::PROCESSED,
        'completed'  => ChannelStatus::COMPLETED,
        'cancelled'  => ChannelStatus::CANCELLED,
        'refunded'   => ChannelStatus::RETURNED,
        'failed'     => ChannelStatus::CANCELLED,
        'shipped'    => ChannelStatus::SHIPPED,
    ];

    public static function normalize(?string $channel, ?string $rawCode): ?ChannelStatus
    {
        if ($rawCode === null || $rawCode === '') {
            return null;
        }

        // Sudah kanonik? Skip lookup.
        $canonical = ChannelStatus::tryFrom($rawCode);
        if ($canonical !== null && $canonical !== ChannelStatus::UNKNOWN) {
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

        // Fallback insensitive lookup (webhook kadang berubah case).
        $upper = strtoupper($rawCode);
        foreach ($map as $key => $value) {
            if (strtoupper((string) $key) === $upper) {
                return $value;
            }
        }

        Log::warning('ChannelStatusNormalizer: kode tidak dikenal', [
            'channel'  => $channel,
            'raw_code' => $rawCode,
        ]);

        return ChannelStatus::UNKNOWN;
    }
}
