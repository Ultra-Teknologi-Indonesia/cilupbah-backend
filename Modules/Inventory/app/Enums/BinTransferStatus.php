<?php

namespace Modules\Inventory\Enums;

/**
 * BinTransfer TIDAK punya kolom `status` di DB — lifecycle dilacak lewat
 * `bin_transfer_receipts` (satu receipt per fase). Enum ini adalah pandangan
 * gabungan yang di-derive dari relasi receipts, bukan cast kolom.
 *
 * Gunakan `$transfer->derivedStatus(): BinTransferStatus` di model.
 */
enum BinTransferStatus: string
{
    case DRAFT      = 'DRAFT';
    case APPROVED   = 'APPROVED';
    case IN_TRANSIT = 'IN_TRANSIT';
    case RECEIVED   = 'RECEIVED';
    case CANCELLED  = 'CANCELLED';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::DRAFT      => [self::APPROVED, self::CANCELLED],
            self::APPROVED   => [self::IN_TRANSIT, self::DRAFT, self::CANCELLED],
            self::IN_TRANSIT => [self::RECEIVED, self::APPROVED],
            self::RECEIVED,
            self::CANCELLED  => [],
        }, true);
    }
}
