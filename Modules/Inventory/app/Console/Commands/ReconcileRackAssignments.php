<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Services\RackImport\RackAssignmentService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Services\SkuHomeBinGuard;

class ReconcileRackAssignments extends Command
{
    private const CONFIRMATION = 'RECONCILE-RACK-ASSIGNMENTS';

    protected $signature = 'inventory:reconcile-rack-assignments
        {--location= : UUID gudang kecil tertentu; kosong berarti seluruh gudang kecil aktif}
        {--sku= : Filter SKU (contains)}
        {--limit=0 : Batas SKU yang diperiksa; 0 berarti tanpa batas}
        {--apply : Terapkan kandidat yang aman}
        {--confirm= : Wajib bernilai RECONCILE-RACK-ASSIGNMENTS saat --apply}';

    protected $description = 'Menyelaraskan assignment rak gudang kecil dari stok aktif yang sudah ditempatkan dan dikonfirmasi.';

    public function handle(RackAssignmentService $rackAssignmentService): int
    {
        $apply = (bool) $this->option('apply');
        $sku = trim((string) $this->option('sku'));
        $limit = $this->validatedLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        if ($apply && $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Mode tulis ditolak. Tambahkan --confirm='.self::CONFIRMATION.'.');

            return self::FAILURE;
        }

        $locations = $this->strictLocations();
        if ($locations->isEmpty()) {
            $this->warn('Tidak ada gudang kecil aktif yang sesuai filter.');

            return self::SUCCESS;
        }

        $summary = [
            'scanned' => 0,
            'already_aligned' => 0,
            'ready_create' => 0,
            'ready_update' => 0,
            'skipped_multiple_active_racks' => 0,
            'assignments_without_active_stock' => 0,
            'applied_created' => 0,
            'applied_updated' => 0,
            'apply_skipped_changed' => 0,
            'apply_failed' => 0,
        ];
        $samples = [];
        $processed = 0;

        $this->line('Mode: '.($apply ? '<fg=red;options=bold>APPLY</>' : '<fg=yellow;options=bold>DRY-RUN (TANPA TULIS)</>'));

        foreach ($locations as $location) {
            $summary['assignments_without_active_stock'] += $this->assignmentsWithoutActiveStockCount($location->id, $sku);

            foreach ($this->activeRackGroups($location->id, $sku) as $group) {
                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }

                $processed++;
                $summary['scanned']++;
                $plan = $this->plan($group);
                $summary[$plan['status']]++;
                $this->appendSample($samples, $plan);

                if (! $apply || ! in_array($plan['status'], ['ready_create', 'ready_update'], true)) {
                    continue;
                }

                try {
                    $outcome = DB::transaction(function () use ($location, $group, $rackAssignmentService): string {
                        $currentPlan = $this->plan($this->freshGroup($location->id, $group['item_id']));

                        if (! in_array($currentPlan['status'], ['ready_create', 'ready_update'], true)) {
                            return 'apply_skipped_changed';
                        }

                        $rackAssignmentService->assign(
                            $location->id,
                            $currentPlan['target_bin_id'],
                            $group['item_id'],
                            null,
                        );

                        return $currentPlan['status'] === 'ready_create'
                            ? 'applied_created'
                            : 'applied_updated';
                    }, 3);

                    $summary[$outcome]++;
                } catch (\Throwable $exception) {
                    $summary['apply_failed']++;
                    $this->error('Gagal merekonsiliasi '.$plan['sku'].': '.$exception->getMessage());
                    $this->appendSample($samples, array_merge($plan, [
                        'status' => 'apply_failed',
                        'reason' => $exception->getMessage(),
                    ]));
                }
            }
        }

        $this->table(
            ['Status', 'Jumlah'],
            collect($summary)
                ->map(fn (int $value, string $key) => [str_replace('_', ' ', strtoupper($key)), $value])
                ->values()
                ->all(),
        );

        if ($samples !== []) {
            $this->newLine();
            $this->table(
                ['Status', 'SKU', 'Gudang', 'Rak Stok', 'Rak Assignment', 'Keterangan'],
                array_map(fn (array $sample) => [
                    strtoupper($sample['status']),
                    $sample['sku'],
                    $sample['location_name'],
                    implode(', ', $sample['active_bin_codes']),
                    $sample['assignment_bin_code'] ?? '—',
                    $sample['reason'],
                ], $samples),
            );
        }

        if (! $apply) {
            $this->warn('Dry-run selesai. Tidak ada data yang diubah. Hanya READY CREATE dan READY UPDATE yang boleh diterapkan.');
        }

        return $summary['apply_failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validatedLimit(): ?int
    {
        $rawLimit = (string) $this->option('limit');
        if (! ctype_digit($rawLimit)) {
            $this->error('--limit harus berupa 0 atau bilangan bulat positif.');

            return null;
        }

        return (int) $rawLimit;
    }

    private function strictLocations()
    {
        $locationId = trim((string) $this->option('location'));

        return Location::query()
            ->where('is_small_warehouse', true)
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->when($locationId !== '', fn ($query) => $query->whereKey($locationId))
            ->orderBy('id')
            ->get(['id', 'location_name']);
    }

    private function activeRackGroups(string $locationId, string $sku): iterable
    {
        $current = null;
        $group = null;

        foreach ($this->activeRackQuery($locationId, $sku)->cursor() as $row) {
            if ($current !== $row->item_id) {
                if ($group !== null) {
                    yield $group;
                }

                $current = $row->item_id;
                $group = [
                    'item_id' => $row->item_id,
                    'location_id' => $locationId,
                    'location_name' => $row->location_name,
                    'sku' => $row->sku,
                    'assignment_bin_id' => $row->assignment_bin_id,
                    'assignment_bin_code' => $row->assignment_bin_code,
                    'active_bins' => [],
                ];
            }

            $group['active_bins'][$row->bin_id] = $row->bin_final_code;
        }

        if ($group !== null) {
            yield $group;
        }
    }

    private function activeRackQuery(string $locationId, string $sku)
    {
        return DB::table('inventories as i')
            ->join('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'i.bin_id')
                    ->on('b.location_id', '=', 'i.location_id');
            })
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->join('product_variants as v', 'v.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('sku_rack_assignments as a', function ($join): void {
                $join->on('a.item_id', '=', 'i.item_id')
                    ->on('a.location_id', '=', 'i.location_id');
            })
            ->leftJoin('location_bins as ab', 'ab.id', '=', 'a.bin_id')
            ->where('i.location_id', $locationId)
            ->where('b.is_inbound', false)
            ->where('b.is_stock_acknowledged', true)
            ->where('b.bin_final_code', '!=', SkuHomeBinGuard::DEFAULT_BIN_CODE)
            ->where(function ($query): void {
                $query->where('i.on_hand', '>', 0)
                    ->orWhere('i.on_order', '>', 0);
            })
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($sku !== '', fn ($query) => $query->whereRaw('UPPER(v.sku) LIKE ?', ['%'.strtoupper($sku).'%']))
            ->select([
                'i.item_id',
                'b.id as bin_id',
                'b.bin_final_code',
                'l.location_name',
                'v.sku',
                'a.bin_id as assignment_bin_id',
                'ab.bin_final_code as assignment_bin_code',
            ])
            ->distinct()
            ->orderBy('i.item_id')
            ->orderBy('b.id');
    }

    private function freshGroup(string $locationId, string $itemId): array
    {
        $assignment = SkuRackAssignment::query()
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        $activeBins = Inventory::query()
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->where(function ($query): void {
                $query->where('on_hand', '>', 0)
                    ->orWhere('on_order', '>', 0);
            })
            ->whereHas('bin', function ($query): void {
                $query->where('is_inbound', false)
                    ->where('is_stock_acknowledged', true)
                    ->where('bin_final_code', '!=', SkuHomeBinGuard::DEFAULT_BIN_CODE);
            })
            ->lockForUpdate()
            ->with('bin:id,bin_final_code')
            ->get()
            ->mapWithKeys(fn (Inventory $inventory) => [$inventory->bin_id => $inventory->bin?->bin_final_code ?? $inventory->bin_id])
            ->all();

        return [
            'item_id' => $itemId,
            'assignment_bin_id' => $assignment?->bin_id,
            'active_bins' => $activeBins,
        ];
    }

    private function plan(array $group): array
    {
        $activeBins = $group['active_bins'];
        $activeBinIds = array_keys($activeBins);
        $assignmentBinId = $group['assignment_bin_id'];
        $base = array_merge($group, [
            'active_bin_codes' => array_values($activeBins),
            'target_bin_id' => null,
            'reason' => '',
        ]);

        if ($assignmentBinId !== null && array_key_exists($assignmentBinId, $activeBins)) {
            return array_merge($base, [
                'status' => 'already_aligned',
                'reason' => 'Assignment masih menunjuk salah satu rak yang berisi stok aktif.',
            ]);
        }

        if (count($activeBinIds) !== 1) {
            return array_merge($base, [
                'status' => 'skipped_multiple_active_racks',
                'reason' => 'SKU memiliki stok aktif pada lebih dari satu rak; tidak aman memilih assignment otomatis.',
            ]);
        }

        return array_merge($base, [
            'status' => $assignmentBinId === null ? 'ready_create' : 'ready_update',
            'target_bin_id' => $activeBinIds[0],
            'reason' => $assignmentBinId === null
                ? 'Satu rak stok aktif yang tervalidasi ditemukan; assignment dapat dibuat.'
                : 'Assignment lama tidak memiliki stok aktif; dapat dipindahkan ke satu-satunya rak stok aktif.',
        ]);
    }

    private function assignmentsWithoutActiveStockCount(string $locationId, string $sku): int
    {
        return DB::table('sku_rack_assignments as a')
            ->join('product_variants as v', 'v.id', '=', 'a.item_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('a.location_id', $locationId)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($sku !== '', fn ($query) => $query->whereRaw('UPPER(v.sku) LIKE ?', ['%'.strtoupper($sku).'%']))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('inventories as i')
                    ->join('location_bins as b', function ($join): void {
                        $join->on('b.id', '=', 'i.bin_id')
                            ->on('b.location_id', '=', 'i.location_id');
                    })
                    ->whereColumn('i.item_id', 'a.item_id')
                    ->whereColumn('i.location_id', 'a.location_id')
                    ->whereColumn('i.bin_id', 'a.bin_id')
                    ->where('b.is_inbound', false)
                    ->where('b.is_stock_acknowledged', true)
                    ->where('b.bin_final_code', '!=', SkuHomeBinGuard::DEFAULT_BIN_CODE)
                    ->where(function ($stock): void {
                        $stock->where('i.on_hand', '>', 0)
                            ->orWhere('i.on_order', '>', 0);
                    });
            })
            ->count();
    }

    private function appendSample(array &$samples, array $plan): void
    {
        if (count($samples) >= 20 || ! in_array($plan['status'], [
            'ready_create',
            'ready_update',
            'skipped_multiple_active_racks',
            'apply_failed',
        ], true)) {
            return;
        }

        $samples[] = $plan;
    }
}
