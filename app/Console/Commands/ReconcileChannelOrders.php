<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelReconciliationService;
use Modules\Channel\Services\ChannelSyncSettingService;

class ReconcileChannelOrders extends Command
{
    protected $signature = 'channel:reconcile-orders {--days=2 : Bandingkan order channel dalam N hari terakhir}';

    protected $description = 'Audit order channel vs lokal (read-only) dan discovery retur Shopee yang belum tercatat (auto-enqueue).';

    public function handle(ChannelReconciliationService $reconciliation, ChannelSyncSettingService $sync): int
    {
        $days = (int) $this->option('days');

        foreach ($reconciliation->auditOrders($days) as $row) {
            if (isset($row['error'])) {
                $this->warn("AUDIT {$row['channel']} {$row['shop_id']}: ERROR {$row['error']}");

                continue;
            }

            $line = "AUDIT {$row['channel']} {$row['shop_id']}: channel={$row['channel_count']} lokal={$row['local_count']} missing={$row['missing_count']}";
            $row['missing_count'] > 0 ? $this->warn($line) : $this->info($line);
        }

        if ($sync->isPaused()) {
            $this->info('Sinkronisasi channel dijeda — discovery retur (enqueue) dilewati.');

            return self::SUCCESS;
        }

        foreach ($reconciliation->discoverShopeeReturns() as $stat) {
            $this->info("DISCOVERY shopee {$stat['shop_id']}: dilihat={$stat['seen']} enqueue={$stat['enqueued']}");
        }

        return self::SUCCESS;
    }
}
