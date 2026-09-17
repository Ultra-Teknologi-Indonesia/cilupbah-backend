<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\SalesOrderService;

class ReconcileStaleBundleOrderItems extends Command
{
    protected $signature = 'sales:reconcile-stale-bundle-items
        {--apply : Terapkan kandidat yang lolos validasi; tanpa opsi ini hanya dry-run}
        {--limit=0 : Batas item yang dipindai (0 = semua)}';

    protected $description = 'Audit dan perbaiki item order channel yang masih menunjuk master non-bundle/deleted padahal bundle aktif tersedia.';

    public function handle(SalesOrderService $service): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $query = SalesOrderItem::query()
            ->with('order')
            ->whereNotNull('item_id')
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery
                    ->whereIn('status', ['pending', 'reserved'])
                    ->whereIn('source', ['shopee', 'tiktok', 'lazada', 'woocommerce'])
                    ->whereNull('handed_to_warehouse_at');
            })
            ->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('picklist_items as pi')
                    ->whereColumn('pi.order_item_id', 'sales_order_items.id');
            })
            ->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('packlist_items as pli')
                    ->whereColumn('pli.order_item_id', 'sales_order_items.id');
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $scanned = 0;
        $eligible = 0;
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY-RUN / READ-ONLY'));

        foreach ($query->get() as $item) {
            $scanned++;
            $order = $item->order;

            if ($order === null) {
                $skipped++;

                continue;
            }

            $target = $service->inspectStaleBundleOrderItem($order, $item);
            if ($target === null) {
                $skipped++;

                continue;
            }

            $eligible++;
            $this->line(sprintf(
                '%s / %s → %s',
                $order->salesorder_no,
                $item->sku,
                $target->target_variant_sku,
            ));

            if (! $apply) {
                continue;
            }

            try {
                if ($service->reconcileStaleBundleOrderItem($order, (string) $item->id)) {
                    $repaired++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                $this->error(sprintf(
                    '  gagal %s / %s: %s',
                    $order->salesorder_no,
                    $item->sku,
                    $exception->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai. Dipindai: %d · kandidat aman: %d · %s: %d · dilewati: %d · gagal: %d.',
            $scanned,
            $eligible,
            $apply ? 'berhasil diperbaiki' : 'siap diperbaiki',
            $apply ? $repaired : $eligible,
            $skipped,
            $failed,
        ));

        if (! $apply) {
            $this->line('Tidak ada perubahan database. Jalankan ulang dengan --apply setelah hasil dry-run diverifikasi.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
