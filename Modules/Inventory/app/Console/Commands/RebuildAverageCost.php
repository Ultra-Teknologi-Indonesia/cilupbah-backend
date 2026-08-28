<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Product\Models\ProductVariant;

final class RebuildAverageCost extends Command
{
    private const CONFIRMATION = 'REBUILD-PURCHASE-AVERAGE-COST';

    protected $signature = 'inventory:rebuild-avg-cost
                            {--item= : Batasi ke item_id (UUID) tertentu}
                            {--batch=500 : Jumlah SKU per batch, 50-2000}
                            {--dry-run : Kompatibilitas; dry-run sudah menjadi mode default}
                            {--apply : Terapkan hasil ke inventories.avg_cost}
                            {--confirm= : Wajib REBUILD-PURCHASE-AVERAGE-COST saat --apply}';

    protected $description = 'Audit atau sinkronkan avg_cost seluruh rak ke rata-rata tertimbang penerimaan pembelian.';

    public function __construct(
        private readonly PurchaseCostService $purchaseCostService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        set_time_limit(0);
        DB::connection()->disableQueryLog();

        $apply = (bool) $this->option('apply');
        $onlyItem = $this->option('item');
        $batchSize = max(50, min(2000, (int) $this->option('batch')));

        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('ABORT: pilih salah satu mode --dry-run atau --apply.');

            return self::FAILURE;
        }

        if ($apply && $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('ABORT: --apply wajib disertai --confirm='.self::CONFIRMATION);

            return self::FAILURE;
        }

        $this->info($apply
            ? 'Mode APPLY: menyamakan avg_cost dengan rata-rata tertimbang pembelian.'
            : 'Mode DRY-RUN / READ-ONLY: database tidak diubah.');

        $summary = [
            'items_scanned' => 0,
            'items_changed' => 0,
            'inventory_rows_changed' => 0,
            'items_without_purchase_cost' => 0,
        ];
        $detailsPrinted = 0;

        $query = ProductVariant::query()
            ->select(['id', 'sku'])
            ->whereHas('inventories')
            ->when($onlyItem, fn (Builder $builder, string $id): Builder => $builder->whereKey($id))
            ->orderBy('id');

        $query->chunkById($batchSize, function (Collection $variants) use (
            $apply,
            &$summary,
            &$detailsPrinted,
        ): void {
            $itemIds = $variants->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $purchaseCosts = $this->purchaseCostService->averageForItemIds($itemIds);

            foreach ($variants as $variant) {
                $summary['items_scanned']++;
                $itemId = (string) $variant->id;
                $newCost = $purchaseCosts[$itemId] ?? null;

                if ($newCost === null || $newCost <= 0) {
                    $summary['items_without_purchase_cost']++;

                    continue;
                }

                $inventoryQuery = Inventory::query()->where('item_id', $itemId);
                $costState = (clone $inventoryQuery)
                    ->selectRaw('COUNT(*) AS row_count')
                    ->selectRaw('MIN(COALESCE(avg_cost, 0)) AS min_cost')
                    ->selectRaw('MAX(COALESCE(avg_cost, 0)) AS max_cost')
                    ->first();

                $changedRows = (clone $inventoryQuery)
                    ->whereRaw('ABS(COALESCE(avg_cost, 0) - ?) >= ?', [$newCost, 0.005])
                    ->count();

                if ($changedRows === 0) {
                    continue;
                }

                $summary['items_changed']++;
                $summary['inventory_rows_changed'] += $changedRows;

                if ($detailsPrinted < 100) {
                    $this->line(sprintf(
                        'sku=%s item=%s rows=%d old_range=%s..%s purchase_average=%s',
                        $variant->sku,
                        $itemId,
                        $changedRows,
                        number_format((float) ($costState->min_cost ?? 0), 4, '.', ''),
                        number_format((float) ($costState->max_cost ?? 0), 4, '.', ''),
                        number_format($newCost, 4, '.', ''),
                    ));
                    $detailsPrinted++;
                }

                if ($apply) {
                    DB::transaction(function () use ($inventoryQuery, $newCost): void {
                        (clone $inventoryQuery)
                            ->whereRaw('ABS(COALESCE(avg_cost, 0) - ?) >= ?', [$newCost, 0.005])
                            ->update([
                                'avg_cost' => round($newCost, 4),
                                'updated_at' => now(),
                            ]);
                    }, 3);
                }
            }

            $this->line(sprintf(
                'PROGRESS items=%d changed_items=%d changed_rows=%d no_purchase_cost=%d',
                $summary['items_scanned'],
                $summary['items_changed'],
                $summary['inventory_rows_changed'],
                $summary['items_without_purchase_cost'],
            ));
        }, 'id');

        if ($detailsPrinted === 100 && $summary['items_changed'] > 100) {
            $this->line('Detail dibatasi 100 SKU; seluruh SKU tetap dipindai per batch.');
        }

        $this->newLine();
        $this->table(
            ['Mode', 'SKU dipindai', 'SKU berubah', 'Row inventory berubah', 'Tanpa harga pembelian'],
            [[
                $apply ? 'APPLY' : 'DRY-RUN',
                $summary['items_scanned'],
                $summary['items_changed'],
                $summary['inventory_rows_changed'],
                $summary['items_without_purchase_cost'],
            ]],
        );

        $this->info($apply ? 'APPLY selesai.' : 'DRY-RUN selesai; database tidak diubah.');

        return self::SUCCESS;
    }
}
