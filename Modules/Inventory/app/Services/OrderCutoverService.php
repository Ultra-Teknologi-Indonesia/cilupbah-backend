<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OrderCutoverService
{
    private const MAX_ISSUES = 200;

    private const CSV_TIMEZONE = 'Asia/Jakarta';

    private const REFERENCE_COLUMNS = [
        'salesorder_no',
        'channel_order_no',
        'nomor',
        'no_pesanan',
        'no_order',
        'order_no',
    ];

    private const TIMESTAMP_COLUMNS = [
        'transaction_date',
        'tgl_pesanan',
        'created_date',
        'created_at',
    ];

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
                'cutoff_at_wib' => $cutoff->setTimezone(self::CSV_TIMEZONE)->toDateTimeString(),
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
        $invalidCsvLocations = $this->invalidCsvLocations($parsed['references'], $locations);

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
        if ($invalidCsvLocations !== []) {
            $issues[] = [
                'reason' => 'order_csv_bukan_gudang_kecil',
                'count' => count($invalidCsvLocations),
                'sample' => array_slice($invalidCsvLocations, 0, self::MAX_ISSUES),
            ];
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
            'cutoff_at_wib' => $cutoff->setTimezone(self::CSV_TIMEZONE)->toDateTimeString(),
            'location_codes' => $locationCodes,
            'locations' => $locations->map(fn ($row): array => [
                'code' => (string) $row->location_code,
                'name' => (string) $row->location_name,
                'active' => (bool) $row->is_active,
            ])->values()->all(),
            'files' => $parsed['files'],
            'cutoff_source' => 'latest_csv_order_timestamp',
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

    public function deriveCutoffFromFiles(array $files, array $fileMeta = []): CarbonImmutable
    {
        $latest = null;
        foreach ($files as $file) {
            if (! is_readable($file)) {
                throw new RuntimeException("file CSV tidak dapat dibaca: {$file}");
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException("file CSV tidak dapat dibuka: {$file}");
            }
            try {
                $header = fgetcsv($handle, 0, ',', '"', '\\');
                if ($header === false) {
                    throw new RuntimeException("file CSV kosong: {$file}");
                }
                $columns = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $header);
                $referenceIndex = $this->findColumnIndex($columns, self::REFERENCE_COLUMNS);
                if ($referenceIndex === null) {
                    throw new RuntimeException("file CSV {$file} wajib memiliki kolom Nomor atau salesorder_no.");
                }
                $timestampIndexes = $this->findColumnIndexes($columns, self::TIMESTAMP_COLUMNS);
                if ($timestampIndexes === []) {
                    throw new RuntimeException("file CSV {$file} wajib memiliki kolom tanggal order (transaction_date atau Tgl.Pesanan).");
                }
                $rowNumber = 1;
                while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;
                    $reference = trim((string) ($values[$referenceIndex] ?? ''));
                    if ($reference === '') {
                        continue;
                    }
                    $rawTimestamp = '';
                    foreach ($timestampIndexes as $timestampIndex) {
                        $candidateTimestamp = trim((string) ($values[$timestampIndex] ?? ''));
                        if ($candidateTimestamp !== '') {
                            $rawTimestamp = $candidateTimestamp;
                            break;
                        }
                    }
                    if ($rawTimestamp === '') {
                        throw new RuntimeException(sprintf(
                            'file CSV %s baris %d (%s) tidak memiliki tanggal/jam order.',
                            $fileMeta[$file]['original_name'] ?? basename($file),
                            $rowNumber,
                            $reference,
                        ));
                    }
                    $timestamp = $this->parseCsvTimestamp($rawTimestamp);
                    if ($timestamp === null) {
                        throw new RuntimeException(sprintf(
                            'file CSV %s baris %d (%s) memiliki tanggal/jam tidak valid: %s.',
                            $fileMeta[$file]['original_name'] ?? basename($file),
                            $rowNumber,
                            $reference,
                            $rawTimestamp,
                        ));
                    }
                    if ($latest === null || $timestamp->greaterThan($latest)) {
                        $latest = $timestamp;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        if ($latest === null) {
            throw new RuntimeException('seluruh file CSV tidak memiliki tanggal/jam order yang valid.');
        }

        return $latest->utc();
    }

    public function apply(
        array $filePaths,
        CarbonImmutable $cutoff,
        array $locationCodes,
        array $fileMeta = [],
        bool $allowPartial = false,
    ): array {
        $audit = $this->preview($filePaths, $cutoff, $locationCodes, $fileMeta);
        if ((int) ($audit['blocking'] ?? 0) > 0 && ! $allowPartial) {
            throw new RuntimeException('apply order cutover dibatalkan karena audit memiliki blocking issue.');
        }

        $lookup = $this->referenceLookup($this->readReferences($filePaths, $fileMeta)['references']);
        $locations = DB::table('locations')->whereIn('location_code', $locationCodes)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $deleted = 0;
        DB::transaction(function () use ($locations, $cutoff, $lookup, $allowPartial, &$deleted): void {
            $query = $this->candidateQuery($locations, $cutoff, array_keys($lookup));
            if ($allowPartial) {
                $this->restrictToSafeCandidates($query);
            }
            $query->select('sales_orders.id')->orderBy('sales_orders.id')->chunkById(500, function ($rows) use (&$deleted): void {
                $ids = $rows->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                $deleted += DB::table('sales_orders')->whereIn('id', $ids)->delete();
            }, 'sales_orders.id', 'id');
        }, 3);

        return [
            'mode' => $allowPartial ? 'APPLY_PARTIAL' : 'APPLY',
            'allow_partial' => $allowPartial,
            'deleted_orders' => $deleted,
            'audit' => $audit,
            'applied_at' => now()->toIso8601String(),
        ];
    }

    private function restrictToSafeCandidates(Builder $query): void
    {
        $query->where(function (Builder $safe): void {
            $safe->whereRaw("LOWER(COALESCE(sales_orders.status, '')) NOT IN ('picked', 'packed', 'shipped', 'completed', 'delivered', 'ready-to-ship')");
            if (Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
                $safe->whereNull('sales_orders.handed_to_warehouse_at');
            }
        });

        foreach (self::ORDER_CHILD_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'order_id')) {
                continue;
            }

            $query->whereNotExists(function (Builder $child) use ($table): void {
                $child->selectRaw('1')
                    ->from($table.' as child')
                    ->whereColumn('child.order_id', 'sales_orders.id');
            });
        }
    }

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
            $referenceIndex = $this->findColumnIndex($columns, self::REFERENCE_COLUMNS);
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

    private function findColumnIndex(array $columns, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $found = array_search($candidate, $columns, true);
            if ($found !== false) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $candidates
     * @return array<int, int>
     */
    private function findColumnIndexes(array $columns, array $candidates): array
    {
        $indexes = [];
        foreach ($candidates as $candidate) {
            $found = array_search($candidate, $columns, true);
            if ($found !== false) {
                $indexes[] = $found;
            }
        }

        return $indexes;
    }

    private function parseCsvTimestamp(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        $value = str_ireplace(
            [' Jan ', ' Feb ', ' Mar ', ' Apr ', ' Mei ', ' May ', ' Jun ', ' Jul ', ' Agt ', ' Aug ', ' Sep ', ' Okt ', ' Oct ', ' Nov ', ' Des ', ' Dec '],
            [' Jan ', ' Feb ', ' Mar ', ' Apr ', ' May ', ' May ', ' Jun ', ' Jul ', ' Aug ', ' Aug ', ' Sep ', ' Oct ', ' Oct ', ' Nov ', ' Dec ', ' Dec '],
            ' '.$value.' ',
        );
        $value = trim($value);
        foreach (['!d M Y H:i:s', '!d M Y H:i', '!Y-m-d H:i:sP', '!Y-m-d H:iP', '!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, self::CSV_TIMEZONE);
                $errors = \DateTimeImmutable::getLastErrors();
                $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
                if ($parsed !== false && ! $hasErrors) {
                    return $parsed->setTimezone(self::CSV_TIMEZONE);
                }
            } catch (\Throwable) {

            }
        }

        return null;
    }

    private function invalidCsvLocations(array $references, Collection $locations): array
    {
        $allowed = $locations
            ->flatMap(fn ($location): array => [(string) $location->location_code, (string) $location->location_name])
            ->map(fn (string $value): string => $this->normalizeLocation($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $invalid = [];
        foreach ($references as $reference => $data) {
            $location = $this->normalizeLocation((string) ($data['location'] ?? ''));
            if ($location !== '' && ! in_array($location, $allowed, true)) {
                $invalid[] = $reference;
            }
        }

        return $invalid;
    }

    private function normalizeLocation(string $value): string
    {
        return strtoupper(trim(preg_replace('/\\s+/', ' ', $value) ?: ''));
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\\xEF\\xBB\\xBF/', '', $header) ?: $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?: '';

        return trim($header, '_');
    }

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
