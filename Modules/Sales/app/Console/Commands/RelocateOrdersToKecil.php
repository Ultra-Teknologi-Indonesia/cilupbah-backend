<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Warehouse\Models\Location;

class RelocateOrdersToKecil extends Command
{
    protected $signature = 'orders:relocate-to-kecil
        {--dry-run : Hanya tampilkan preview, tidak menulis apa pun}
        {--limit=0 : Batasi jumlah order yang diproses (0 = semua)}
        {--chunk=100 : Ukuran chunk saat iterasi order}';

    protected $description = 'Backfill semua pesanan channel (source != manual) yang masih pending/reserved supaya lokasinya = Gudang Kecil.';

    public function handle(SalesOrderService $service): int
    {
        $kecilId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');

        if (! $kecilId) {
            $this->error('Gudang Kecil (location_code = WH-KECIL) belum di-seed. Jalankan migration terlebih dahulu.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');
        $chunk  = max(1, (int) $this->option('chunk'));

        $baseQuery = SalesOrder::query()
            ->whereNotNull('source')
            ->where('source', '!=', 'manual')
            ->where('is_manual', false)
            ->whereIn('status', ['pending', 'reserved'])
            ->where(function ($q) use ($kecilId) {
                $q->whereNull('location_id')
                    ->orWhere('location_id', '!=', $kecilId);
            });

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $this->info('Tidak ada pesanan channel yang perlu di-relocate.');
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d pesanan yang perlu di-relocate ke Gudang Kecil (limit = %s).',
            $dryRun ? '[DRY-RUN]' : 'Menemukan',
            $total,
            $limit > 0 ? (string) $limit : 'tanpa batas',
        ));

        $success = 0;
        $failed  = 0;
        $skipped = 0;
        $processed = 0;

        $query = (clone $baseQuery)->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById($chunk, function ($orders) use (
            $service,
            $kecilId,
            $dryRun,
            &$success,
            &$failed,
            &$skipped,
            &$processed,
            $limit,
        ) {
            foreach ($orders as $order) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;

                if ($order->location_id === $kecilId) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [DRY-RUN] %s (%s) status=%s location_id=%s → %s',
                        $order->salesorder_no,
                        $order->source,
                        $order->status,
                        $order->location_id ?? 'NULL',
                        $kecilId,
                    ));
                    $success++;
                    continue;
                }

                try {
                    $service->relocateOrder($order, $kecilId, enforce: false);
                    $success++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn(sprintf(
                        '  ✗ %s gagal di-relocate: %s',
                        $order->salesorder_no,
                        $e->getMessage(),
                    ));
                }
            }

            return true;
        });

        $this->newLine();
        $this->info(sprintf(
            'Selesai. Sukses: %d · Gagal: %d · Skipped: %d · Total diproses: %d',
            $success,
            $failed,
            $skipped,
            $processed,
        ));

        if ($dryRun) {
            $this->line('Ini adalah dry-run — tidak ada perubahan yang ditulis.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
