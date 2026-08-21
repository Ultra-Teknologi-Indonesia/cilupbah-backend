<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\BackfillShippedOrdersStockService;

class BackfillShippedOrdersStockCommand extends Command
{
    protected $signature = 'orders:backfill-shipped-stock
        {--dry-run : Hanya simulasikan pemotongan stok tanpa menulis ke database}
        {--order= : Nomor pesanan / channel_order_no spesifik}
        {--limit= : Batasi jumlah pesanan yang diproses}';

    protected $description = 'Backfill pemotongan stok fisik dari Gudang Kecil untuk pesanan yang sudah shipped/completed tetapi belum dipick di WMS.';

    public function handle(BackfillShippedOrdersStockService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orderNo = $this->option('order') ? (string) $this->option('order') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Mencari pesanan shipped/completed yang belum potong stok...');

        $orders = $service->getEligibleOrders($orderNo, $limit);

        if ($orders->isEmpty()) {
            $this->info('Tidak ada pesanan yang perlu di-backfill. Semua stok sudah sinkron!');

            return self::SUCCESS;
        }

        $this->info(sprintf('Ditemukan %d pesanan yang belum potong stok fisik.%s',
            $orders->count(),
            $dryRun ? ' [MODE DRY-RUN - Tidak ada data yang diubah]' : ''
        ));

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        $processedCount = 0;
        $totalDeductions = 0;
        $failedCount = 0;

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
