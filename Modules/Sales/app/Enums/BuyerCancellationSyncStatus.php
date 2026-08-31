<?php

namespace Modules\Sales\Enums;

enum BuyerCancellationSyncStatus: string
{
    case PENDING = 'pending';
    case SENDING = 'sending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case UNSUPPORTED = 'unsupported';
    case STALE = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu dikirim ke channel',
            self::SENDING => 'Mengirim keputusan ke channel',
            self::SUCCEEDED => 'Keputusan berhasil dikirim ke channel',
            self::FAILED => 'Gagal mengirim keputusan ke channel',
            self::UNSUPPORTED => 'Belum didukung oleh channel',
            self::STALE => 'Status channel sudah berubah',
        };
    }
}
