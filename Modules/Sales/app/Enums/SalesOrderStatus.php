<?php

namespace Modules\Sales\Enums;

/**
 * `sales_orders.status` di DB punya dua konvensi tumpang tindih:
 *
 * (1) Lowercase FSM murni yang dipakai SalesOrderService::ALLOWED_TRANSITIONS
 *     (pending → reserved → picked → packed → shipped, dengan cabang ke cancelled)
 *
 * (2) UPPERCASE state yang ditulis oleh mapper channel + beberapa Job:
 *     - UNPAID: default DB, dipakai mapper Shopee/TikTok/Lazada/WC saat order baru
 *     - AWAITING_BUYER_CONFIRMATION: ProcessPicklistCompleteJob saat item shortage
 *     - READY: ShopeeOrderService saat proses siap-kirim
 *
 * Enum ini merangkum keduanya. Method canonical() memberi projeksi
 * ke lowercase FSM untuk kode yang butuh state machine.
 */
enum SalesOrderStatus: string
{
    case PENDING   = 'pending';
    case RESERVED  = 'reserved';
    case PICKED    = 'picked';
    case PACKED    = 'packed';
    case SHIPPED   = 'shipped';
    case CANCELLED = 'cancelled';

    case UNPAID                       = 'UNPAID';
    case READY                        = 'READY';
    case AWAITING_BUYER_CONFIRMATION  = 'AWAITING_BUYER_CONFIRMATION';

    public function canonical(): self
    {
        return match ($this) {
            self::UNPAID                      => self::PENDING,
            self::READY                       => self::PACKED,
            self::AWAITING_BUYER_CONFIRMATION => self::RESERVED,
            default                           => $this,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->canonical(), match ($this->canonical()) {
            self::PENDING   => [self::RESERVED, self::CANCELLED],
            self::RESERVED  => [self::PICKED,   self::CANCELLED],
            self::PICKED    => [self::PACKED,   self::CANCELLED],
            self::PACKED    => [self::SHIPPED,  self::CANCELLED],
            self::SHIPPED,
            self::CANCELLED => [],
            default         => [],
        }, true);
    }
}
