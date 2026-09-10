<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OrderCutoverService
{
    private const MAX_ISSUES = 200;

    /** @var array<int, string> */
    private const ORDER_CHILD_TABLES = [
        'sales_order_items',
        'sales_order_fee_lines',
        'sales_order_status_histories',
        'order_buyer_confirmations',
        'order_bin_allocations',
        'sales_invoices',
        'channel_settlement_adjustments',
        'sales_returns',
        'warranties',
        'bulk_shipping_label_items',
        'shipment_orders',
        'picklist_items',
        'packlists',
        'fulfillment_removals',
    ];

    /**
     * @param array<int, string> $filePaths
     * @param array<string, array<string, mixed>> $fileMeta
     * @param array<int, string> $locationCodes
     */
    public function preview(
        array $filePaths,
        CarbonImmutable $cutoff,
        array $locationCodes,
        array $fileMeta = [],
    ): array {
        $parsed = $this->readReferences($filePaths, $fileMeta);
        $locations = DB::table('locations')
            ->whereIn('location_code', $locationCodes)
            ->get(['id', 'location_code', 'location_name', 'is_active']);
        $missingLocations = array_values(array_diff($locationCodes, $locations->pluck('location_code')->map(fn ($v): string => strtoupper((string) $v))->all()));
        $locationIds = $locations->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $inactiveLocations = $locations->filter(fn ($location): bool => ! (bool) $location->is_active)->pluck('location_code')->map(fn ($code): string => (string) $code)->values()->all();

        if ($locationIds === []) {
            return [
                'mode' => 'CSV_WHITELIST_PLUS_NEWER',
                'cutoff_at' => $cutoff->utc()->toIso8601String(),
                'file_order_count' => count($parsed['references']),
                'location_codes' => $locationCodes,
                'missing_locations' => $missingLocations,
                'blocking' => count($missingLocations) + 1,
                'issues' => [['reason' => 'lokasi_tidak_ditemukan', 'locations' => $missingLocations]],
            ];
        }

        $lookup = $this->referenceLookup($parsed['references']);
        $internal = $this->findInternalMatches($locationIds, $lookup);
        $matchedReferences = array_keys($internal['by_reference']);
        $missingReferences = array_values(array_diff(array_keys($parsed['references']), $matchedReferences));

        $scope = DB::table('sales_orders')->whereIn('location_id', $locationIds);
        $newerCount = (clone $scope)->where(function (Builder $query) use ($cutoff): void {
            $query->where('created_at', '>=', $cutoff->utc())
                ->orWhere('transaction_date', '>=', $cutoff->utc());
        })->count();
        $totalCount = (clone $scope)->count();
        $candidate = $this->candidateQuery($locationIds, $cutoff, array_keys($lookup));
        $deleteCount = (clone $candidate)->count();

        $statusCounts = (clone $candidate)
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $processedQuery = (clone $candidate)->whereRaw("LOWER(COALESCE(status, '')) IN ('picked', 'packed', 'shipped', 'completed', 'delivered', 'ready-to-ship')");
        if (Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
            $processedQuery = (clone $candidate)->where(function (Builder $query): void {
                $query->whereRaw("LOWER(COALESCE(status, '')) IN ('picked', 'packed', 'shipped', 'completed', 'delivered', 'ready-to-ship')")
                    ->orWhereNotNull('handed_to_warehouse_at');
            });
        }
        $processedCount = $processedQuery->count();
        $childCounts = $this->dependentCounts($candidate);

        $issues = [];
        if ($missingLocations !== []) {
            $issues[] = ['reason' => 'lokasi_tidak_ditemukan', 'locations' => $missingLocations];
        }
        if ($inactiveLocations !== []) {
            $issues[] = ['reason' => 'lokasi_tidak_aktif', 'locations' => $inactiveLocations];
        }
        if ($missingReferences !== []) {
            $issues[] = ['reason' => 'order_csv_tidak_ditemukan_di_internal', 'count' => count($missingReferences), 'sample' => array_slice($missingReferences, 0, self::MAX_ISSUES)];
        }
        if ($internal['ambiguous'] !== []) {
            $issues[] = ['reason' => 'order_csv_memiliki_duplikat_internal', 'count' => count($internal['ambiguous']), 'sample' => array_slice($internal['ambiguous'], 0, self::MAX_ISSUES, true)];
        }
        if ($processedCount > 0) {
            $issues[] = ['reason' => 'order_kandidat_sudah_masuk_proses_gudang', 'count' => $processedCount];
        }
        foreach ($childCounts as $table => $count) {
            if ($count > 0) {
                $issues[] = ['reason' => 'order_kandidat_memiliki_relasi_'.$table, 'count' => $count];
            }
        }

        return [
            'mode' => 'CSV_WHITELIST_PLUS_NEWER',
            'cutoff_at' => $cutoff->utc()->toIso8601String(),
            'location_codes' => $locationCodes,
            'locations' => $locations->map(fn ($row): array => [
                'code' => (string) $row->location_code,
                'name' => (string) $row->location_name,
                'active' => (bool) $row->is_active,
            ])->values()->all(),
            'files' => $parsed['files'],
            'file_order_count' => count($parsed['references']),
            'internal_found_count' => count($matchedReferences),
            'missing_order_count' => count($missingReferences),
            'ambiguous_order_count' => count($internal['ambiguous']),
            'orders_in_scope' => $totalCount,
            'csv_orders_kept' => count($internal['ids']),
            'newer_orders_kept' => $newerCount,
            'orders_to_delete' => $deleteCount,
            'candidate_status_counts' => $statusCounts,
            'dependent_rows_on_candidates' => $childCounts,
            'issues' => $issues,
            'blocking' => count($issues),
            'rule' => 'order yang cocok dengan CSV dan order lebih baru dari cutoff dipertahankan; kandidat lain hanya boleh dihapus jika belum diproses gudang dan tidak memiliki relasi child.',
            'whitelist_order_ids' => array_values($internal['ids']),
        ];
    }

    /**
     * @param array<int, string> $filePaths
     * @param array<string, array<string, mixed>> $fileMeta
     */
    public function apply(
        array $filePaths,
        CarbonImmutable $cutoff,
        array $locationCodes,
        array $fileMeta = [],
    ): array {
        $audit = $this->preview($filePaths, $cutoff, $locationCodes, $fileMeta);
        if ((int) ($audit['blocking'] ?? 0) > 0) {
            throw new RuntimeException('apply order cutover dibatalkan karena audit memiliki blocking issue.');
        }

        $lookup = $this->referenceLookup($this->readReferences($filePaths, $fileMeta)['references']);
        $locations = DB::table('locations')->whereIn('location_code', $locationCodes)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $deleted = 0;
        DB::transaction(function () use ($locations, $cutoff, $lookup, &$deleted): void {
            $query = $this->candidateQuery($locations, $cutoff, array_keys($lookup));
            $query->select('sales_orders.id')->orderBy('sales_orders.id')->chunkById(500, function ($rows) use (&$deleted): void {
                $ids = $rows->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                $deleted += DB::table('sales_orders')->whereIn('id', $ids)->delete();
            }, 'sales_orders.id', 'id');
        }, 3);

        return [
            'mode' => 'APPLY',
            'deleted_orders' => $deleted,
            'audit' => $audit,
            'applied_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<int, string> $files */
    private function readReferences(array $files, array $fileMeta): array
    {
        $references = [];
        $fileReports = [];
        foreach ($files as $file) {
            if (! is_readable($file)) {
                throw new RuntimeException("file CSV tidak dapat dibaca: {$file}");
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException("file CSV tidak dapat dibuka: {$file}");
            }
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                fclose($handle);
                throw new RuntimeException("file CSV kosong: {$file}");
            }
            $columns = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $header);
            $referenceIndex = null;
            foreach (['salesorder_no', 'channel_order_no', 'nomor', 'no_pesanan', 'no_order', 'order_no'] as $candidate) {
                $found = array_search($candidate, $columns, true);
                if ($found !== false) {
                    $referenceIndex = $found;
                    break;
                }
            }
            if ($referenceIndex === null) {
                fclose($handle);
                throw new RuntimeException("file CSV {$file} wajib memiliki kolom Nomor atau salesorder_no.");
            }
            $locationIndex = array_search('lokasi', $columns, true);
            $locationIndex = $locationIndex === false ? array_search('location_name', $columns, true) : $locationIndex;
            $rowCount = 0;
            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowCount++;
                $reference = trim((string) ($values[$referenceIndex] ?? ''));
                if ($reference === '') {
                    continue;
                }
                $references[$reference] ??= [
                    'reference' => $reference,
                    'location' => $locationIndex === false ? '' : trim((string) ($values[$locationIndex] ?? '')),
                    'sources' => [],
                ];
                $references[$reference]['sources'][] = $fileMeta[$file]['category'] ?? basename($file);
            }
            fclose($handle);
            $fileReports[] = [
                'path' => $fileMeta[$file]['original_name'] ?? basename($file),
                'category' => $fileMeta[$file]['category'] ?? null,
                'sha256' => hash_file('sha256', $file),
                'row_count' => $rowCount,
            ];
        }
        if ($references === []) {
            throw new RuntimeException('seluruh file CSV tidak memiliki nomor pesanan.');
        }

        return ['references' => $references, 'files' => $fileReports];
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\\xEF\\xBB\\xBF/', '', $header) ?: $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?: '';

        return trim($header, '_');
    }

    /** @param array<string, array<string, mixed>> $references */
    private function referenceLookup(array $references): array
    {
        $lookup = [];
        foreach (array_keys($references) as $reference) {
            foreach ($this->referenceVariants($reference) as $variant) {
                $lookup[$variant] = $reference;
            }
        }

        return $lookup;
    }

    /** @return array<int, string> */
    private function referenceVariants(string $reference): array
    {
        $reference = trim($reference);
        $variants = [$reference];
        if (preg_match('/^(SP|LZ)-(.+)$/i', $reference, $matches)) {
            $variants[] = $matches[2];
        }
        if (preg_match('/^(TT|TP)-(.+?)(?:-\d+)?$/i', $reference, $matches)) {
            $variants[] = $matches[2];
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /** @param array<int, string> $locationIds @param array<string, string> $lookup */
    private function findInternalMatches(array $locationIds, array $lookup): array
    {
        $byReference = [];
        $ids = [];
        $ambiguous = [];
        foreach (array_chunk(array_keys($lookup), 500) as $chunk) {
            DB::table('sales_orders')
                ->whereIn('location_id', $locationIds)
                ->where(function (Builder $query) use ($chunk): void {
                    $query->whereIn('salesorder_no', $chunk)->orWhereIn('channel_order_no', $chunk);
                })
                ->get(['id', 'salesorder_no', 'channel_order_no'])
                ->each(function ($order) use (&$byReference, &$ids, &$ambiguous, $lookup): void {
                    foreach ([$order->salesorder_no, $order->channel_order_no] as $value) {
                        if ($value === null) {
                            continue;
                        }
                        foreach ($this->referenceVariants((string) $value) as $variant) {
                            $reference = $lookup[$variant] ?? null;
                            if ($reference === null) {
                                continue;
                            }
                            $byReference[$reference] ??= [];
                            if (! collect($byReference[$reference])->contains(fn ($match): bool => (string) $match->id === (string) $order->id)) {
                                $byReference[$reference][] = $order;
                            }
                            $ids[(string) $order->id] = true;
                        }
                    }
                });
        }
        foreach ($byReference as $reference => $matches) {
            if (count($matches) > 1) {
                $ambiguous[$reference] = count($matches);
            }
        }

        return ['by_reference' => $byReference, 'ids' => array_keys($ids), 'ambiguous' => $ambiguous];
    }

    /** @param array<int, string> $locationIds @param array<int, string> $lookupKeys */
    private function candidateQuery(array $locationIds, CarbonImmutable $cutoff, array $lookupKeys): Builder
    {
        return DB::table('sales_orders')
            ->whereIn('sales_orders.location_id', $locationIds)
            ->where(function (Builder $notMatch) use ($lookupKeys): void {
                $notMatch->where(function (Builder $q) use ($lookupKeys): void {
                    $q->whereNull('sales_orders.salesorder_no')->orWhereNotIn('sales_orders.salesorder_no', $lookupKeys);
                })->where(function (Builder $q) use ($lookupKeys): void {
                    $q->whereNull('sales_orders.channel_order_no')->orWhereNotIn('sales_orders.channel_order_no', $lookupKeys);
                });
            })
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where(function (Builder $old) use ($cutoff): void {
                    $old->where(function (Builder $q) use ($cutoff): void {
                        $q->whereNull('sales_orders.created_at')->orWhere('sales_orders.created_at', '<', $cutoff->utc());
                    })->where(function (Builder $q) use ($cutoff): void {
                        $q->whereNull('sales_orders.transaction_date')->orWhere('sales_orders.transaction_date', '<', $cutoff->utc());
                    });
                });
            });
    }

    /** @return array<string, int> */
    private function dependentCounts(Builder $candidate): array
    {
        $counts = [];
        foreach (self::ORDER_CHILD_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'order_id')) {
                continue;
            }
            $counts[$table] = (int) DB::table($table.' as child')
                ->join('sales_orders as so', 'so.id', '=', 'child.order_id')
                ->whereExists(function (Builder $query) use ($candidate): void {
                    $query->selectRaw('1')->fromSub($candidate, 'candidate')->whereColumn('candidate.id', 'so.id');
                })
                ->count();
        }

        return $counts;
    }
}
