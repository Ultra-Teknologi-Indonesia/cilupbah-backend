<?php

namespace Modules\Sales\Enums;

enum WmsStatus: string
{
    case OTHER          = 'OTHER';
    case CREATED        = 'CREATED';
    case PAID           = 'PAID';
    case PROCESS        = 'PROCESS';
    case PICK           = 'PICK';
    case FINISH_PICK    = 'FINISH_PICK';
    case PACK           = 'PACK';
    case FINISH_PACK    = 'FINISH_PACK';
    case READY_TO_SHIP  = 'READY_TO_SHIP';
    case SHIPPED        = 'SHIPPED';
    case COMPLETED      = 'COMPLETED';
    case CANCELLED      = 'CANCELLED';
    case FAILED         = 'FAILED';
    case RETURNED       = 'RETURNED';

    public function label(): string
    {
        return match ($this) {
            self::CREATED, self::PAID, self::OTHER => 'Menunggu Pembayaran',
            self::PROCESS => 'Pengambilan - Belum Dimulai',
            self::PICK => 'Pengambilan - Sedang Diproses',
            self::FINISH_PICK => 'Pengambilan - Selesai',
            self::PACK => 'Pengepakan - Sedang Diproses',
            self::FINISH_PACK => 'Pengepakan - Selesai',
            self::READY_TO_SHIP => 'Pengiriman - Siap Dikirim',
            self::SHIPPED => 'Pengiriman - Sedang Dikirim',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::FAILED => 'Gagal Pengambilan',
            self::RETURNED => 'Diretur',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::FAILED, self::RETURNED], true);
    }
}
