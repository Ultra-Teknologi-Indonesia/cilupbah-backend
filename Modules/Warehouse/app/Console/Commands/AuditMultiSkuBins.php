<?php

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Models\Inventory;

class AuditMultiSkuBins extends Command
{
    protected $signature = 'bins:audit-multi-sku
        {--location= : Batasi audit pada satu location_id.}';

    protected $description = 'Daftar rak yang berisi lebih dari satu SKU aktif (pelanggar aturan 1 rak 1 SKU).';

    public function handle(): int
    {
        try {
            $locationFilter = $this->option('location');

            $inventories = Inventory::query()
                ->with(['bin.location', 'product.product'])
                ->whereNotNull('bin_id')
                ->whereHas('bin', function ($q) use ($locationFilter) {
                    $q->where('is_inbound', false)
                        ->where('is_stock_acknowledged', true);
                    if ($locationFilter) {
                        $q->where('location_id', $locationFilter);
                    }
                })
                ->where(function ($w) {
                    $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
                })
                ->get();

            $violations = $inventories
                ->groupBy('bin_id')
                ->filter(function ($rows) {
                    return $rows->pluck('item_id')->unique()->count() > 1;
                });

            if ($violations->isEmpty()) {
                $this->info('Tidak ada rak dengan lebih dari satu SKU aktif.');
                return self::SUCCESS;
            }

            $this->warn(sprintf('%d rak melanggar aturan 1 rak 1 SKU:', $violations->count()));
            $this->newLine();

            foreach ($violations as $binId => $rows) {
                $bin = $rows->first()->bin;
                $this->line(sprintf(
                    '  · Lokasi %s (%s) — Rak %s (bin_id=%s)',
                    $bin?->location?->location_code ?? '-',
                    $bin?->location?->location_name ?? '-',
                    $bin?->bin_final_code ?? '-',
                    $binId
                ));

                foreach ($rows->groupBy('item_id') as $itemGroup) {
                    $first = $itemGroup->first();
                    $variant = $first->product;
                    $this->line(sprintf(
                        '      - %s (%s)  on_hand=%d  on_order=%d',
                        $variant?->sku ?? $first->item_id,
                        $variant?->product?->name ?? '-',
                        (int) $itemGroup->sum('on_hand'),
                        (int) $itemGroup->sum('on_order')
                    ));
                }
            }

            $this->newLine();
            $this->warn('Bersihkan lewat menu Pindah Bin (Internal Transfer). Guard baru menolak transaksi berikutnya, tapi data existing perlu dirapikan manual.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Gagal audit rak multi-SKU: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
