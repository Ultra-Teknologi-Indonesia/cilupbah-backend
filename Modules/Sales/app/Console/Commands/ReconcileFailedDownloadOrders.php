<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;

class ReconcileFailedDownloadOrders extends Command
{
    protected $signature = 'sales:reconcile-failed-downloads
        {--apply : Terapkan pemetaan yang berhasil ditemukan; tanpa opsi ini hanya dry-run}
        {--limit=0 : Batas jumlah order (0 = semua)}';

    protected $description = 'Rekonsiliasi item order channel yang item_id-nya kosong tanpa menarik ulang produk dari marketplace.';

    public function handle(
        SalesOrderRepository $repository,
        SalesOrderService $service,
    ): int {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $baseQuery = SalesOrderItem::query()
            ->whereNull('item_id')
            ->whereHas('order', function ($query): void {
                $query->whereNotNull('source')
                    ->where('source', '!=', 'manual');
            });

        $totalItems = (clone $baseQuery)->count();
        $orderIds = (clone $baseQuery)
            ->select('order_id')
            ->distinct()
            ->orderBy('order_id')
            ->pluck('order_id');

        if ($limit > 0) {
            $orderIds = $orderIds->take($limit)->values();
        }

        $this->line(sprintf(
            '%s %d item pada %d order channel yang item_id-nya kosong.',
            $apply ? '[APPLY]' : '[DRY-RUN]',
            $totalItems,
            $orderIds->count(),
        ));

        $resolved = 0;
        $unresolved = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            $order = SalesOrder::with('items')->find($orderId);

            if (! $order) {
                continue;
            }

            foreach ($order->items->whereNull('item_id') as $item) {
                $variantId = $repository->variantIdForChannelOrderItem(
                    (string) $order->id,
                    $item->channel_product_id,
                    $item->sku,
                );

                if (! $variantId) {
                    $unresolved++;
                    $this->warn(sprintf(
                        '  belum ada mapping: %s / %s',
                        $order->salesorder_no,
                        $item->sku ?: '(tanpa SKU)',
                    ));

                    continue;
                }

                if (! $apply) {
                    $resolved++;
                    $this->line(sprintf(
                        '  dapat dipetakan: %s / %s → %s',
                        $order->salesorder_no,
                        $item->sku ?: '(tanpa SKU)',
                        $variantId,
                    ));

                    continue;
                }

                try {
                    $service->downloadOrderItem($order, $item->id, $variantId);
                    $resolved++;
                    $order = $order->fresh(['items']);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error(sprintf(
                        '  gagal diperbaiki: %s / %s — %s',
                        $order->salesorder_no,
                        $item->sku ?: '(tanpa SKU)',
                        $e->getMessage(),
                    ));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai. %s: %d · belum ada mapping: %d · gagal: %d.',
            $apply ? 'berhasil diperbaiki' : 'siap diperbaiki',
            $resolved,
            $unresolved,
            $failed,
        ));

        if (! $apply) {
            $this->line('Tidak ada perubahan database. Jalankan ulang dengan --apply setelah hasil dry-run sesuai.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
