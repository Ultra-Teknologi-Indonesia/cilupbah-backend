<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\BackfillShippedOrdersStockService;

class BackfillShippedOrdersStockCommand extends Command
{
    protected $signature = 'orders:backfill-shipped-stock
        {--dry-run : Hanya simulasikan pemotongan stok tanpa menulis ke database}
        {--order= : Nomor pesanan / channel_order_no spesifik}
        {--since=2026-08-16 : Batas tanggal awal pesanan (default 2026-08-16)}
        {--chunk=100 : Ukuran chunk per batch}
        {--limit= : Batasi jumlah total pesanan yang diproses}';

    protected $description = 'Backfill pemotongan stok fisik dari Gudang Kecil untuk pesanan yang sudah shipped/completed tetapi belum dipick di WMS.';

    public function handle(BackfillShippedOrdersStockService $service): int
    {
        @ini_set('memory_limit', '1024M');

        $dryRun = (bool) $this->option('dry-run');
        $orderNo = $this->option('order') ? (string) $this->option('order') : null;
        $since = $this->option('since') ? (string) $this->option('since') : null;
        $chunkSize = max(10, (int) ($this->option('chunk') ?: 100));
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info(sprintf('Mencari pesanan shipped/completed yang belum potong stok (sejak %s)...', $since ?? 'awal'));

        $query = $service->getEligibleOrdersQuery($orderNo, $since, $limit);
        $totalCount = (clone $query)->count();

        if ($totalCount === 0) {
            $this->info('Tidak ada pesanan yang perlu di-backfill. Semua stok sudah sinkron!');

            return self::SUCCESS;
        }

        $this->info(sprintf('Ditemukan %d pesanan yang belum potong stok fisik.%s',
            $totalCount,
            $dryRun ? ' [MODE DRY-RUN - Tidak ada data yang diubah]' : ''
        ));

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $processedCount = 0;
        $totalDeductions = 0;
        $failedCount = 0;

        $query->chunkById($chunkSize, function ($orders) use ($service, $dryRun, $bar, &$processedCount, &$totalDeductions, &$failedCount) {
            foreach ($orders as $order) {
                $result = $service->backfillOrder($order, $dryRun);

                if ($result['success']) {
                    $processedCount++;
                    $totalDeductions += count($result['deductions']);
                } else {
                    $failedCount++;
                    $this->newLine();
                    $this->warn(sprintf('  [GAGAL] %s: %s', $order->salesorder_no, $result['message'] ?? 'Error'));
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s: %d pesanan berhasil diproses (%d mutasi baris rak), %d gagal.',
            $dryRun ? '[DRY-RUN SELESAI]' : '[BACKFILL SELESAI]',
            $processedCount,
            $totalDeductions,
            $failedCount
        ));

        return self::SUCCESS;
    }
}
