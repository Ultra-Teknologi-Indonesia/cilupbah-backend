<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelReconciliationService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Sales\Jobs\AdminAlertJob;

class ReconcileChannelOrders extends Command
{
    protected $signature = 'channel:reconcile-orders
        {--days=2 : Bandingkan order channel dalam N hari terakhir}
        {--backfill-limit=50 : Maksimum order hilang yang ditarik ulang per toko}';

    protected $description = 'Audit order channel vs lokal, tarik ulang order yang belum masuk, dan discovery retur Shopee yang belum tercatat.';

    public function handle(ChannelReconciliationService $reconciliation, ChannelSyncSettingService $sync): int
    {
        $days = (int) $this->option('days');
        $backfillLimit = (int) $this->option('backfill-limit');
        $paused = $sync->isPaused();

        foreach ($reconciliation->auditOrders($days) as $row) {
            if (isset($row['error'])) {
                $this->warn("AUDIT {$row['channel']} {$row['shop_id']}: ERROR {$row['error']}");

                continue;
            }

            $line = "AUDIT {$row['channel']} {$row['shop_id']}: channel={$row['channel_count']} lokal={$row['local_count']} missing={$row['missing_count']}";
            $row['missing_count'] > 0 ? $this->warn($line) : $this->info($line);

            if ($row['missing_count'] === 0 || $paused) {
                continue;
            }

            $this->backfill($reconciliation, $row, $backfillLimit);
        }

        if ($paused) {
            $this->info('Sinkronisasi channel dijeda — backfill order & discovery retur dilewati.');

            return self::SUCCESS;
        }

        foreach ($reconciliation->discoverShopeeReturns() as $stat) {
            $this->info("DISCOVERY shopee {$stat['shop_id']}: dilihat={$stat['seen']} enqueue={$stat['enqueued']}");
        }

        return self::SUCCESS;
    }

    private function backfill(ChannelReconciliationService $reconciliation, array $row, int $limit): void
    {
        $hasil = $reconciliation->pullMissingOrders(
            $row['channel'],
            $row['shop_id'],
            $row['missing'] ?? [],
            $limit,
        );

        $this->info("BACKFILL {$row['channel']} {$row['shop_id']}: ditarik={$hasil['pulled']} gagal={$hasil['failed']}");

        if ($hasil['failed'] === 0) {
            return;
        }

        AdminAlertJob::dispatch(
            "Pesanan {$row['channel']} gagal masuk ke sistem",
            "{$hasil['failed']} pesanan ada di marketplace tapi tetap gagal ditarik setelah backfill otomatis.",
            [
                'channel' => $row['channel'],
                'shop_id' => $row['shop_id'],
                'order_ids' => array_slice($hasil['failed_ids'], 0, 20),
            ],
        );
    }
}
