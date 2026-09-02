<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BackfillRackImportPutaway extends Command
{
    protected $signature = 'inventory:backfill-rack-import-putaway
                            {--batch= : Batasi ke rack import batch id atau nomor batch}
                            {--transaction= : Batasi ke nomor transaksi RACK-IMPORT}
                            {--limit=1000 : Maksimal baris import yang dipindai}
                            {--source-empty : Hanya tampilkan kandidat legacy yang source bin-nya benar-benar kosong}
                            {--apply : Simpan koreksi metadata putaway. Tanpa flag ini hanya dry-run}';

    protected $description = 'Audit dan backfill metadata putaway dari import alokasi rak lama tanpa mengubah stok atau movement.';

    public function handle(): int
    {
        if (! Schema::hasColumn('inbound_items', 'reserved_qty')) {
            $this->error('Kolom inbound_items.reserved_qty belum tersedia. Backfill dibatalkan.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10000],
        ]);

        if ($limit === false) {
            $this->error('--limit harus berupa bilangan bulat 1 sampai 10000.');

            return self::FAILURE;
        }

        DB::connection()->disableQueryLog();

        $scanned = 0;
        $results = $this->option('apply')
            ? $this->scan($limit, $scanned)
            : DB::transaction(function () use ($limit, &$scanned): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION READ ONLY');
                }

                return $this->scan($limit, $scanned);
            });

        $this->line('Mode: '.($this->option('apply') ? 'APPLY' : 'DRY-RUN / READ ONLY'));
        $this->line('Baris import alokasi rak dipindai: '.$scanned);
        $this->line('Kandidat aman: '.count($results['ready']));
        $this->line('Perlu review / tidak dapat dipastikan: '.count($results['review']));

        $displayRows = array_slice(array_merge($results['ready'], $results['review']), 0, 200);
        if ($displayRows !== []) {
            $this->table(
                ['Batch', 'SKU', 'Source On Hand', 'Rak Tujuan', 'Qty', 'Putaway', 'Status', 'Keterangan'],
                array_map(static fn (array $row): array => [
                    $row['batch_no'],
                    $row['sku'],
                    $row['source_on_hand'] ?? '-',
                    $row['target_bin'],
                    $row['qty'],
                    $row['putaway_no'],
                    $row['status'],
                    $row['message'],
                ], $displayRows),
            );

            $totalResults = count($results['ready']) + count($results['review']);
            if ($totalResults > count($displayRows)) {
                $this->line('Menampilkan '.count($displayRows).' dari '.$totalResults.' hasil; gunakan --batch atau --transaction untuk audit lebih spesifik.');
            }
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run selesai: tidak ada inventory, movement, atau metadata yang diubah.');
            $this->line('Jalankan ulang dengan --apply hanya setelah seluruh kandidat READY ditinjau.');

            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;
        foreach ($results['ready'] as $plan) {
            try {
                DB::transaction(function () use ($plan): void {
                    $this->applyPlan($plan);
                });
                $applied++;
            } catch (\Throwable $e) {
                $skipped++;
                $this->warn(sprintf(
                    'Dilewati %s / %s: %s',
                    $plan['batch_no'],
                    $plan['sku'],
                    $e->getMessage(),
                ));
            }
        }

        $this->info("Backfill selesai: {$applied} kandidat diterapkan, {$skipped} dilewati karena kondisi berubah.");
        $this->line('Inventory dan inventory_movements tidak diubah.');

        return self::SUCCESS;
    }

    private function scan(int $limit, int &$scanned): array
    {
        $query = DB::table('rack_import_rows as row')
            ->join('rack_import_batches as batch', 'batch.id', '=', 'row.batch_id')
            ->join('product_variants as variant', 'variant.id', '=', 'row.item_id')
            ->join('location_bins as bin', 'bin.id', '=', 'row.bin_id')
            ->where('row.status', 'placed')
            ->whereNotNull('row.item_id')
            ->whereNotNull('row.location_id')
            ->whereNotNull('row.bin_id')
            ->select([
                'row.id as row_id',
                'row.item_id',
                'row.location_id',
                'row.bin_id',
                'row.created_at as row_created_at',
                'batch.id as batch_id',
                'batch.batch_no',
                'batch.executed_by',
                'batch.created_at as batch_created_at',
                'batch.updated_at as batch_updated_at',
                'variant.sku',
                'bin.bin_final_code as target_bin',
            ])
            ->orderBy('row.id');

        $batchFilter = trim((string) $this->option('batch'));
        if ($batchFilter !== '') {
            if (Str::isUuid($batchFilter)) {
                $query->where('batch.id', $batchFilter);
            } else {
                $query->where('batch.batch_no', $batchFilter);
            }
        }

        $ready = [];
        $review = [];

        foreach ($query->limit($limit)->get() as $row) {
            $scanned++;
            $base = [
                'row_id' => (string) $row->row_id,
                'batch_id' => (string) $row->batch_id,
                'batch_no' => (string) $row->batch_no,
                'sku' => (string) $row->sku,
                'target_bin' => (string) $row->target_bin,
                'bin_id' => (string) $row->bin_id,
            ];

            $pair = $this->findMovementPair($row);
            if ($pair === []) {

                continue;
            }

            if ($pair === null) {
                $review[] = $base + [
                    'status' => 'REVIEW_REQUIRED',
                    'qty' => '-',
                    'putaway_no' => '-',
                    'message' => 'Pasangan mutasi RACK_PLACEMENT_OUT/IN yang unik tidak ditemukan.',
                ];

                continue;
            }

            $sourceInventory = $this->sourceInventoryState($row, $pair['source_bin_id']);
            $base['source_inventory_exists'] = $sourceInventory['exists'];
            $base['source_on_hand'] = $sourceInventory['on_hand'];

            if ($this->option('source-empty')) {
                if (! $sourceInventory['exists']) {
                    $review[] = $base + [
                        'status' => 'REVIEW_REQUIRED',
                        'qty' => $pair['qty'],
                        'putaway_no' => '-',
                        'message' => 'Inventory source bin tidak ditemukan; kondisi kosong tidak dapat dipastikan.',
                    ];

                    continue;
                }

                if (! $sourceInventory['empty']) {
                    continue;
                }
            }

            $candidate = $this->findPutawayCandidate($row, $pair['source_bin_id'], $pair['qty'], $pair['last_at']);
            if ($candidate['status'] !== 'READY') {
                $review[] = $base + [
                    'status' => $candidate['status'],
                    'qty' => $pair['qty'],
                    'putaway_no' => $candidate['putaway_no'] ?? '-',
                    'message' => $candidate['message'],
                ];

                continue;
            }

            $ready[] = $base + $pair + [
                'putaway_item_id' => $candidate['putaway_item_id'],
                'putaway_id' => $candidate['putaway_id'],
                'putaway_no' => $candidate['putaway_no'],
                'putaway_qty_before' => $candidate['putaway_qty_before'],
                'source_mode' => $candidate['source_mode'],
                'source_ids' => $candidate['source_ids'],
                'status' => 'READY',
                'message' => 'Mutasi dan sumber inbound tunggal cocok; hanya metadata putaway yang akan diselaraskan.',
            ];
        }

        return ['ready' => $ready, 'review' => $review];
    }

    private function sourceInventoryState(object $row, string $sourceBinId): array
    {
        $inventories = DB::table('inventories')
            ->where('item_id', $row->item_id)
            ->where('location_id', $row->location_id)
            ->where('bin_id', $sourceBinId)
            ->get(['on_hand']);

        if ($inventories->isEmpty()) {
            return ['exists' => false, 'empty' => false, 'on_hand' => null];
        }

        $onHandValues = $inventories->map(static fn (object $inventory): int => (int) $inventory->on_hand);

        return [
            'exists' => true,
            'empty' => $onHandValues->every(static fn (int $onHand): bool => $onHand === 0),
            'on_hand' => $onHandValues->sum(),
        ];
    }

    private function findMovementPair(object $row): ?array
    {
        $movements = DB::table('inventory_movements')
            ->where('item_id', $row->item_id)
            ->where('location_id', $row->location_id)
            ->whereIn('source', ['RACK_PLACEMENT_OUT', 'RACK_PLACEMENT_IN'])
            ->whereBetween('transaction_date', [$row->batch_created_at, $row->batch_updated_at])
            ->when($row->executed_by, fn ($q, $userId) => $q->where('created_by', 'user:'.$userId))
            ->when($this->option('transaction'), fn ($q, $transaction) => $q->where('transaction_number', $transaction))
            ->get(['id', 'transaction_number', 'source', 'qty', 'bin_id', 'transaction_date']);

        if ($movements->isEmpty()) {
            return [];
        }

        $pairs = [];
        foreach ($movements->groupBy('transaction_number') as $transactionNumber => $group) {
            $out = $group->where('source', 'RACK_PLACEMENT_OUT')->filter(fn (object $movement): bool => (int) $movement->qty < 0);
            $in = $group->where('source', 'RACK_PLACEMENT_IN')->filter(function (object $movement) use ($row): bool {
                return (int) $movement->qty > 0 && (string) $movement->bin_id === (string) $row->bin_id;
            });

            if ($out->isEmpty() || $in->isEmpty()) {
                continue;
            }

            $otherInbound = $group->where('source', 'RACK_PLACEMENT_IN')->filter(function (object $movement) use ($row): bool {
                return (int) $movement->qty > 0 && (string) $movement->bin_id !== (string) $row->bin_id;
            });
            $sourceBins = $out->pluck('bin_id')->filter()->unique()->values();
            $outQty = abs((int) $out->sum('qty'));
            $inQty = (int) $in->sum('qty');

            if ($otherInbound->isNotEmpty() || $sourceBins->count() !== 1 || $outQty <= 0 || $outQty !== $inQty) {
                continue;
            }

            $pairs[] = [
                'transaction_number' => (string) $transactionNumber,
                'source_bin_id' => (string) $sourceBins->first(),
                'qty' => $outQty,
                'last_at' => $group->max('transaction_date'),
                'out_ids' => $out->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
                'in_ids' => $in->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
            ];
        }

        return count($pairs) === 1 ? $pairs[0] : null;
    }

    private function findPutawayCandidate(object $row, string $sourceBinId, int $qty, mixed $lastAt): array
    {
        $candidates = DB::table('putaway_items as item')
            ->join('putaways as putaway', 'putaway.id', '=', 'item.putaway_id')
            ->where('putaway.source_type', 'INBOUND')
            ->where('putaway.location_id', $row->location_id)
            ->where('item.item_id', $row->item_id)
            ->where('item.source_bin_id', $sourceBinId)
            ->whereColumn('item.putaway_qty', '<', 'item.qty')
            ->where('item.created_at', '<=', $lastAt)
            ->get([
                'item.id as putaway_item_id',
                'item.putaway_id',
                'item.item_id',
                'item.qty',
                'item.putaway_qty',
                'item.destination_bin_id',
                'putaway.putaway_no',
                'putaway.source_id',
            ]);

        $eligible = $candidates->filter(function (object $candidate) use ($row, $qty): bool {
            if ((int) $candidate->qty - (int) $candidate->putaway_qty < $qty) {
                return false;
            }

            if ($candidate->destination_bin_id !== null
                && (string) $candidate->destination_bin_id !== (string) $row->bin_id) {
                return false;
            }

            $existing = DB::table('putaway_placements')
                ->where('putaway_item_id', $candidate->putaway_item_id)
                ->where('bin_id', $row->bin_id)
                ->sum('qty');

            return (int) $existing === 0;
        })->values();

        if ($eligible->count() !== 1) {
            return [
                'status' => $eligible->isEmpty() ? 'REVIEW_REQUIRED' : 'AMBIGUOUS',
                'message' => $eligible->isEmpty()
                    ? 'Dokumen putaway inbound yang cocok tidak ditemukan atau kuotanya tidak cukup.'
                    : 'Lebih dari satu dokumen putaway cocok; tidak dipilih otomatis.',
            ];
        }

        $candidate = $eligible->first();
        $sourcePlan = $this->validateSources($candidate, $qty);
        if ($sourcePlan['status'] !== 'READY') {
            return $sourcePlan;
        }

        return [
            'status' => 'READY',
            'putaway_item_id' => (string) $candidate->putaway_item_id,
            'putaway_id' => (string) $candidate->putaway_id,
            'putaway_no' => (string) $candidate->putaway_no,
            'putaway_qty_before' => (int) $candidate->putaway_qty,
            'source_mode' => $sourcePlan['source_mode'],
            'source_ids' => $sourcePlan['source_ids'],
        ];
    }

    private function validateSources(object $candidate, int $qty): array
    {
        $sources = DB::table('putaway_item_sources as source')
            ->join('inbound_items as inbound_item', 'inbound_item.id', '=', 'source.inbound_item_id')
            ->where('source.putaway_item_id', $candidate->putaway_item_id)
            ->orderBy('source.id')
            ->get([
                'source.id',
                'source.inbound_item_id',
                'source.qty',
                'source.putaway_qty',
                'inbound_item.received_qty',
                'inbound_item.putaway_qty as inbound_putaway_qty',
                'inbound_item.reserved_qty',
            ]);

        if ($sources->isEmpty()) {
            if (! $candidate->source_id) {
                return ['status' => 'REVIEW_REQUIRED', 'message' => 'Putaway tidak memiliki sumber inbound yang dapat dipastikan.'];
            }

            $fallback = DB::table('inbound_items')
                ->where('inbound_id', $candidate->source_id)
                ->where('item_id', $candidate->item_id ?? null)
                ->get(['id', 'received_qty', 'putaway_qty', 'reserved_qty']);

            if ($fallback->count() !== 1) {
                return ['status' => 'REVIEW_REQUIRED', 'message' => 'Sumber inbound tidak tunggal atau tidak ditemukan.'];
            }

            $inbound = $fallback->first();
            if ((int) $inbound->received_qty - (int) $inbound->putaway_qty < $qty
                || (int) $inbound->reserved_qty < $qty) {
                return ['status' => 'REVIEW_REQUIRED', 'message' => 'Counter sumber inbound tidak mencukupi untuk backfill aman.'];
            }

            return [
                'status' => 'READY',
                'source_mode' => 'fallback',
                'source_ids' => [(string) $inbound->id],
            ];
        }

        $remaining = $qty;
        $sourceIds = [];
        foreach ($sources as $source) {
            if ($remaining <= 0) {
                break;
            }

            $take = min(max(0, (int) $source->qty - (int) $source->putaway_qty), $remaining);
            if ($take <= 0) {
                continue;
            }

            if ((int) $source->received_qty - (int) $source->inbound_putaway_qty < $take
                || (int) $source->reserved_qty < $take) {
                return ['status' => 'REVIEW_REQUIRED', 'message' => 'Counter sumber inbound tidak konsisten; tidak ada perubahan otomatis.'];
            }

            $sourceIds[] = (string) $source->id;
            $remaining -= $take;
        }

        return $remaining === 0
            ? ['status' => 'READY', 'source_mode' => 'linked', 'source_ids' => $sourceIds]
            : ['status' => 'REVIEW_REQUIRED', 'message' => 'Alokasi sumber inbound tidak dapat dipenuhi secara utuh.'];
    }

    private function applyPlan(array $plan): void
    {
        $putawayItem = DB::table('putaway_items as item')
            ->join('putaways as putaway', 'putaway.id', '=', 'item.putaway_id')
            ->where('item.id', $plan['putaway_item_id'])
            ->lockForUpdate()
            ->select([
                'item.id',
                'item.putaway_id',
                'item.putaway_qty',
                'item.qty',
                'item.destination_bin_id',
                'putaway.location_id',
                'putaway.status',
            ])
            ->first();

        if (! $putawayItem || $putawayItem->status === 'CANCELLED') {
            throw new \RuntimeException('Dokumen putaway tidak tersedia atau sudah dibatalkan.');
        }

        if ((int) $putawayItem->putaway_qty !== (int) $plan['putaway_qty_before']) {
            throw new \RuntimeException('Counter putaway berubah setelah dry-run.');
        }

        if ((int) $putawayItem->qty - (int) $putawayItem->putaway_qty < (int) $plan['qty']) {
            throw new \RuntimeException('Sisa kuota putaway tidak lagi mencukupi.');
        }

        if ($putawayItem->destination_bin_id !== null
            && (string) $putawayItem->destination_bin_id !== (string) $plan['bin_id']) {
            throw new \RuntimeException('Putaway sudah memiliki rak tujuan lain.');
        }

        if (DB::table('putaway_placements')
            ->where('putaway_item_id', $putawayItem->id)
            ->where('bin_id', $plan['bin_id'] ?? null)
            ->exists()) {
            throw new \RuntimeException('Placement target sudah ada.');
        }

        $this->validateMovementPlan($plan);
        $this->applySourceCounters($plan);

        DB::table('putaway_items')->where('id', $putawayItem->id)->update([
            'putaway_qty' => (int) $putawayItem->putaway_qty + (int) $plan['qty'],
            'destination_bin_id' => $plan['bin_id'],
            'updated_at' => now(),
        ]);

        DB::table('putaway_placements')->insert([
            'id' => (string) Str::uuid(),
            'putaway_item_id' => $putawayItem->id,
            'bin_id' => $plan['bin_id'],
            'qty' => (int) $plan['qty'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incomplete = DB::table('putaway_items')
            ->where('putaway_id', $putawayItem->putaway_id)
            ->whereColumn('putaway_qty', '<', 'qty')
            ->exists();

        if (! $incomplete) {
            DB::table('putaways')
                ->where('id', $putawayItem->putaway_id)
                ->where('status', 'IN_PROGRESS')
                ->update(['status' => 'COMPLETED', 'completed_at' => now(), 'updated_at' => now()]);
        }

        DB::table('putaways')
            ->where('id', $putawayItem->putaway_id)
            ->update(['updated_version_at' => now(), 'updated_at' => now()]);
    }

    private function validateMovementPlan(array $plan): void
    {
        $ids = array_merge($plan['out_ids'], $plan['in_ids']);
        $rows = DB::table('inventory_movements')
            ->whereIn('id', $ids)
            ->get(['id', 'transaction_number', 'source', 'qty', 'bin_id']);

        if ($rows->count() !== count($ids)
            || $rows->pluck('id')->map(static fn ($id): string => (string) $id)->diff($ids)->isNotEmpty()
            || $rows->where('transaction_number', $plan['transaction_number'])->count() !== count($ids)
            || abs((int) $rows->whereIn('id', $plan['out_ids'])->sum('qty')) !== (int) $plan['qty']
            || (int) $rows->whereIn('id', $plan['in_ids'])->sum('qty') !== (int) $plan['qty']
            || $rows->whereIn('id', $plan['out_ids'])->contains(fn (object $row): bool => $row->source !== 'RACK_PLACEMENT_OUT' || (int) $row->qty >= 0)
            || $rows->whereIn('id', $plan['in_ids'])->contains(fn (object $row): bool => $row->source !== 'RACK_PLACEMENT_IN' || (int) $row->qty <= 0)
            || $rows->whereIn('id', $plan['out_ids'])->contains(fn (object $row): bool => (string) $row->bin_id !== (string) $plan['source_bin_id'])
            || $rows->whereIn('id', $plan['in_ids'])->contains(fn (object $row): bool => (string) $row->bin_id !== (string) $plan['bin_id'])) {
            throw new \RuntimeException('Pasangan mutasi berubah setelah dry-run.');
        }
    }

    private function applySourceCounters(array $plan): void
    {
        $remaining = (int) $plan['qty'];

        if ($plan['source_mode'] === 'fallback') {
            $inbound = DB::table('inbound_items')->where('id', $plan['source_ids'][0])->lockForUpdate()->first();
            if (! $inbound
                || (int) $inbound->received_qty - (int) $inbound->putaway_qty < $remaining
                || (int) $inbound->reserved_qty < $remaining) {
                throw new \RuntimeException('Counter sumber inbound berubah setelah dry-run.');
            }

            DB::table('inbound_items')->where('id', $inbound->id)->update([
                'putaway_qty' => (int) $inbound->putaway_qty + $remaining,
                'reserved_qty' => (int) $inbound->reserved_qty - $remaining,
                'updated_at' => now(),
            ]);

            return;
        }

        $sources = DB::table('putaway_item_sources')
            ->whereIn('id', $plan['source_ids'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sources as $source) {
            if ($remaining <= 0) {
                break;
            }

            $inbound = DB::table('inbound_items')->where('id', $source->inbound_item_id)->lockForUpdate()->first();
            $capacity = max(0, (int) $source->qty - (int) $source->putaway_qty);
            $take = min($capacity, $remaining);
            if (! $inbound
                || (int) $inbound->received_qty - (int) $inbound->putaway_qty < $take
                || (int) $inbound->reserved_qty < $take) {
                throw new \RuntimeException('Counter sumber inbound berubah setelah dry-run.');
            }

            DB::table('putaway_item_sources')->where('id', $source->id)->update([
                'putaway_qty' => (int) $source->putaway_qty + $take,
                'updated_at' => now(),
            ]);
            DB::table('inbound_items')->where('id', $inbound->id)->update([
                'putaway_qty' => (int) $inbound->putaway_qty + $take,
                'reserved_qty' => (int) $inbound->reserved_qty - $take,
                'updated_at' => now(),
            ]);

            $remaining -= $take;
        }

        if ($remaining !== 0) {
            throw new \RuntimeException('Alokasi sumber inbound berubah setelah dry-run.');
        }
    }
}
