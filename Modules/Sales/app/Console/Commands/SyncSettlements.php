<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\SettlementSyncService;

class SyncSettlements extends Command
{
    protected $signature = 'settlements:sync
        {--channel= : Batasi ke satu channel (shopee|tiktok|lazada)}
        {--days=30 : Rentang hari ke belakang (settlement/release window)}';

    protected $description = 'Sinkronkan statement/escrow marketplace (arsip settlement + settled_at) statement-driven';

    public function handle(SettlementSyncService $service): int
    {
        $channel = $this->option('channel');
        $days = (int) $this->option('days');

        $stats = $service->sync($channel ?: null, $days);

        foreach ($stats as $ch => $n) {
            $this->info("{$ch}: {$n} transaksi diproses.");
        }

        return self::SUCCESS;
    }
}
