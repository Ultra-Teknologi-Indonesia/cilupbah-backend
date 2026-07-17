<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Enums\DisputeOutcome;

/**
 * Petakan kode `dispute_outcome` mentah per marketplace ke enum kanonik `DisputeOutcome`.
 * Tidak pernah melempar. Kode tak dikenal → DisputeOutcome::PENDING + log warning.
 */
final class DisputeOutcomeNormalizer
{
    private const SHOPEE = [
        'REQUESTED'           => DisputeOutcome::PENDING,
        'PENDING'             => DisputeOutcome::PENDING,
        'JUDGING'             => DisputeOutcome::PENDING,
        'ACCEPTED'            => DisputeOutcome::BUYER_WIN,
        'BUYER_WIN'           => DisputeOutcome::BUYER_WIN,
        'REJECTED'            => DisputeOutcome::SELLER_WIN,
        'SELLER_WIN'          => DisputeOutcome::SELLER_WIN,
        'SELLER_REFUSE_RETURN'=> DisputeOutcome::SELLER_REFUSE_RETURN,
        'CLOSED'              => DisputeOutcome::CANCELLED,
        'EXPIRED'             => DisputeOutcome::CANCELLED,
        'NO_RETURN_NEEDED'    => DisputeOutcome::NO_RETURN_NEEDED,
        'REFUNDED'            => DisputeOutcome::REFUNDED,
    ];

    private const TIKTOK = [
        'PENDING'             => DisputeOutcome::PENDING,
        'AWAITING_BUYER'      => DisputeOutcome::PENDING,
        'APPROVED'            => DisputeOutcome::BUYER_WIN,
        'COMPLETED'           => DisputeOutcome::BUYER_WIN,
        'REJECTED'            => DisputeOutcome::SELLER_WIN,
        'REJECT_BY_SELLER'    => DisputeOutcome::SELLER_WIN,
        'SELLER_REFUSE_RETURN'=> DisputeOutcome::SELLER_REFUSE_RETURN,
        'CANCELLED'           => DisputeOutcome::CANCELLED,
        'CANCELED'            => DisputeOutcome::CANCELLED,
        'REFUNDED'            => DisputeOutcome::REFUNDED,
    ];

    private const LAZADA = [
        'pending'                => DisputeOutcome::PENDING,
        'awaiting_seller_action' => DisputeOutcome::PENDING,
        'approved'               => DisputeOutcome::BUYER_WIN,
        'accepted'               => DisputeOutcome::BUYER_WIN,
        'rejected'               => DisputeOutcome::SELLER_WIN,
        'seller_refuse_return'   => DisputeOutcome::SELLER_REFUSE_RETURN,
        'closed'                 => DisputeOutcome::CANCELLED,
        'cancelled'              => DisputeOutcome::CANCELLED,
        'refunded'               => DisputeOutcome::REFUNDED,
    ];

    private const WOOCOMMERCE = [
        'pending'   => DisputeOutcome::PENDING,
        'approved'  => DisputeOutcome::BUYER_WIN,
        'rejected'  => DisputeOutcome::SELLER_WIN,
        'refunded'  => DisputeOutcome::REFUNDED,
        'cancelled' => DisputeOutcome::CANCELLED,
    ];

    public static function normalize(?string $channel, ?string $rawCode): ?DisputeOutcome
    {
        if ($rawCode === null || $rawCode === '') {
            return null;
        }

        $canonical = DisputeOutcome::tryFrom($rawCode);
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

        Log::warning('DisputeOutcomeNormalizer: kode tidak dikenal', [
            'channel'  => $channel,
            'raw_code' => $rawCode,
        ]);

        return DisputeOutcome::PENDING;
    }
}
