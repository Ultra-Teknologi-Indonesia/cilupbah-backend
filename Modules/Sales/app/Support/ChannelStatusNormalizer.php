<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Enums\ChannelStatus;

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

    /**
     * Katalog seluruh status mentah yang mungkin dikirim tiap channel, beserta
     * padanan kanoniknya. Dipakai untuk mengisi dropdown filter laporan — daftarnya
     * statis, bukan DISTINCT dari data, supaya semua kemungkinan tetap tampil
     * meski belum pernah ada pesanannya (mengikuti gaya daftar status_mp Jubelio).
     *
     * @return array<string, array<string, ChannelStatus>>
     */
    public static function catalog(): array
    {
        return [
            'shopee'      => self::SHOPEE,
            'tiktok'      => self::TIKTOK,
            'lazada'      => self::LAZADA + self::LAZADA_EXTRA,
            'woocommerce' => self::WOOCOMMERCE,
        ];
    }

    /**
     * Status Lazada yang ditangani mapper tapi belum ada di peta normalisasi.
     * Sebagian muncul juga di daftar Jubelio (LOST BY 3PL, DAMAGED BY 3PL).
     */
    private const LAZADA_EXTRA = [
        'repacked'             => ChannelStatus::READY_TO_SHIP,
        'shipping'             => ChannelStatus::SHIPPED,
        'confirmed'            => ChannelStatus::TO_CONFIRM_RECEIVE,
        'failed_delivery'      => ChannelStatus::SHIPPED,
        'shipped_back'         => ChannelStatus::RETURNED,
        'shipped_back_success' => ChannelStatus::RETURNED,
        'shipped_back_failed'  => ChannelStatus::SHIPPED,
        'lost_by_3pl'          => ChannelStatus::SHIPPED,
        'damaged_by_3pl'       => ChannelStatus::SHIPPED,
    ];

    public static function normalize(?string $channel, ?string $rawCode): ?ChannelStatus
    {
        if ($rawCode === null || $rawCode === '') {
            return null;
        }

        $canonical = ChannelStatus::tryFrom($rawCode);
        if ($canonical !== null && $canonical !== ChannelStatus::UNKNOWN) {
            return $canonical;
        }

        // Satu sumber dengan catalog(), supaya setiap opsi yang bisa dipilih di
        // dropdown laporan dijamin punya padanan kanonik.
        $map = self::catalog()[strtolower((string) $channel)] ?? [];

        if (isset($map[$rawCode])) {
            return $map[$rawCode];
        }

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
