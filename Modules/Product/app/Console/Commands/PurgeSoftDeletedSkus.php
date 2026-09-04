<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeSoftDeletedSkus extends Command
{
    protected $signature = 'products:purge-soft-deleted-skus
                            {--apply : Hapus permanen kandidat yang tidak memiliki referensi}
                            {--product= : Batasi ke satu id master}
                            {--sku-contains= : Batasi sku yang mengandung teks tertentu}';

    protected $description = 'Pratinjau dan hapus permanen sku varian dari master yang sudah soft-delete';

    /** @var array<string, string> */
    private const REFERENCES = [
        'product_variant_channel_mappings' => 'variant_id',
        'sales_order_items' => 'item_id',
        'inventories' => 'item_id',
        'inventory_movements' => 'item_id',
        'reserved_stock_items' => 'item_id',
        'stock_adjustment_items' => 'item_id',
        'stock_opname_items' => 'item_id',
        'putaway_items' => 'item_id',
        'stock_revaluation_items' => 'item_id',
        'inventory_transfer_items' => 'item_id',
        'bin_transfer_items' => 'item_id',
        'picklist_items' => 'item_id',
        'packlist_items' => 'item_id',
        'purchase_bill_items' => 'item_id',
        'purchase_return_items' => 'item_id',
        'sales_invoice_items' => 'item_id',
        'sales_return_items' => 'item_id',
        'order_bin_allocations' => 'item_id',
        'warranties' => 'product_variant_id',
        'product_bundles' => 'bundle_variant_id',
    ];

    public function handle(): int
    {
        $dryRun = ! (bool) $this->option('apply');
        $scanned = 0;
        $deletable = 0;
        $blocked = 0;
        $deleted = 0;
        $blockedBy = [];
        $sample = [];
        $references = array_filter(
            self::REFERENCES,
            fn ($column, $table) => Schema::hasTable($table),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($dryRun) {
            $this->warn('PRATINJAU — tidak ada perubahan data. Tambahkan --apply untuk menghapus permanen.');
        } else {
            $this->warn('MODE APPLY — kandidat tanpa referensi akan dihapus permanen.');
        }

        $query = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereNotNull('pv.deleted_at')
            ->whereNotNull('p.deleted_at')
            ->whereNotNull('pv.sku')
            ->whereRaw("TRIM(pv.sku) <> ''")
            ->when($this->option('product'), fn ($q, $id) => $q->where('pv.product_id', (string) $id))
            ->when($this->option('sku-contains'), fn ($q, $text) => $q->where('pv.sku', 'ilike', '%'.$text.'%'))
            ->select(['pv.id', 'pv.product_id', 'pv.sku', 'p.name as product_name'])
            ->orderBy('pv.id');

        $batch = [];

        foreach ($query->cursor() as $row) {
            $batch[] = $row;

            if (count($batch) >= 100) {
                $this->processBatch(
                    $batch,
                    $references,
                    $dryRun,
                    $scanned,
                    $deletable,
                    $blocked,
                    $deleted,
                    $blockedBy,
                    $sample,
                );
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->processBatch(
                $batch,
                $references,
                $dryRun,
                $scanned,
                $deletable,
                $blocked,
                $deleted,
                $blockedBy,
                $sample,
            );
        }

        if ($sample !== []) {
            $this->table(['id', 'sku', 'master', 'status'], $sample);
        }

        $this->line('sku soft-delete diperiksa: '.$scanned);
        $this->line('kandidat tanpa referensi: '.$deletable);
        $this->line('dilewati karena masih direferensikan: '.$blocked);

        if ($blockedBy !== []) {
            $this->line('referensi penghambat: '.collect($blockedBy)
                ->map(fn ($count, $table) => $table.'='.$count)
                ->implode(', '));
        }

        if ($dryRun) {
            $this->info('DRY-RUN selesai. Tidak ada perubahan data.');
        } else {
            $this->info('sku dihapus permanen: '.$deleted);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $rows
     * @param  array<string, string>  $references
     * @param  array<string, int>  $blockedBy
     * @param  list<array{id: string, sku: string, master: string, status: string}>  $sample
     */
    private function processBatch(
        array $rows,
        array $references,
        bool $dryRun,
        int &$scanned,
        int &$deletable,
        int &$blocked,
        int &$deleted,
        array &$blockedBy,
        array &$sample,
    ): void {
        $variantIds = array_map(fn ($row) => (string) $row->id, $rows);
        $blockedRows = [];

        foreach ($references as $table => $column) {
            $referencedIds = DB::table($table)
                ->whereIn($column, $variantIds)
                ->pluck($column)
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->all();

            foreach ($referencedIds as $variantId) {
                $blockedRows[$variantId][] = $table;
            }
        }

        $deletableIds = [];

        foreach ($rows as $row) {
            $scanned++;
            $rowReferences = $blockedRows[(string) $row->id] ?? [];

            if ($rowReferences !== []) {
                $blocked++;
                foreach ($rowReferences as $reference) {
                    $blockedBy[$reference] = ($blockedBy[$reference] ?? 0) + 1;
                }
                if (count($sample) < 20) {
                    $sample[] = [
                        'id' => $row->id,
                        'sku' => $row->sku,
                        'master' => mb_strimwidth((string) $row->product_name, 0, 55, '…'),
                        'status' => 'dilewati: '.implode(', ', $rowReferences),
                    ];
                }

                continue;
            }

            $deletable++;
            $deletableIds[] = (string) $row->id;
            if (count($sample) < 20) {
                $sample[] = [
                    'id' => $row->id,
                    'sku' => $row->sku,
                    'master' => mb_strimwidth((string) $row->product_name, 0, 55, '…'),
                    'status' => 'aman dihapus',
                ];
            }
        }

        if (! $dryRun && $deletableIds !== []) {
            $deleted += DB::table('product_variants')
                ->whereIn('id', $deletableIds)
                ->whereNotNull('deleted_at')
                ->delete();
        }
    }
}
