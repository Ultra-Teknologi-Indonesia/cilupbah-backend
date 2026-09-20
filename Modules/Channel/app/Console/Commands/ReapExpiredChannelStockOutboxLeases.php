<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelStockSyncOutboxService;

class ReapExpiredChannelStockOutboxLeases extends Command
{
    protected $signature = 'channel:reap-stock-outbox-leases';

    protected $description = 'Memulihkan push stok yang tertahan di status dispatching karena lease kedaluwarsa.';

    public function handle(ChannelStockSyncOutboxService $outbox): int
    {
        $before = $outbox->expiredDispatchingCount();
        $reaped = $outbox->reapExpiredLeases();
        $after = $outbox->expiredDispatchingCount();

        $this->info('Lease outbox stok kedaluwarsa dipulihkan.');
        $this->line('Sebelum: '.$before);
        $this->line('Dipulihkan: '.$reaped);
        $this->line('Tersisa expired: '.$after);
        $this->line('Tidak ada API marketplace yang dipanggil.');

        return self::SUCCESS;
    }
}
