<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class StockCutoverService
{
    private const TERMINAL_STATUSES = ['shipped', 'completed', 'delivered', 'cancelled'];

    private const RESET_LOCATION_TABLES = [
        'inventory_movements', 'stock_adjustments', 'reserved_stocks', 'inventory_transfers',
        'putaways', 'stock_opnames', 'stock_revaluations', 'bin_transfers', 'inbounds',
        'inbound_receipts', 'inbound_backfill_reconciliations', 'picklists', 'packlists',
        'shipments', 'sales_returns', 'bin_transfer_receipts',
    ];

    public function createRun(string $cutoff, array $locationCodes, array $sourceFiles = []): array
    {
        $this->assertRequiredTables();
        $cutoffAt = $this->parseCutoff($cutoff);
        $locations = $this->resolveLocations($locationCodes);
        $runId = (string) Str::uuid();

        DB::table('stock_cutover_runs')->insert([
            'id' => $runId,
            'cutoff_at' => $cutoffAt->utc()->toDateTimeString(),
            'location_codes' => json_encode($locations->pluck('location_code')->values()->all(), JSON_THROW_ON_ERROR),
            'source_files' => json_encode($this->hashFiles($sourceFiles), JSON_THROW_ON_ERROR),
            'report' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'PREFLIGHT',
            'created_by' => (string) (getenv('CUTOVER_OPERATOR') ?: 'artisan'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'run_id' => $runId,
            'cutoff_at' => $cutoffAt->toIso8601String(),
            'location_codes' => $locations->pluck('location_code')->values()->all(),
        ];
    }

    public function getRun(string $runId): array
    {
        $run = DB::table('stock_cutover_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \RuntimeException("run_id '{$runId}' tidak ditemukan, jalankan cutover:preflight terlebih dahulu.");
        }

        $codes = $this->decodeJson($run->location_codes);
        $locations = $this->resolveLocations($codes);

        return [
            'run_id' => (string) $run->id,
            'cutoff_at' => CarbonImmutable::parse($run->cutoff_at)->setTimezone('Asia/Jakarta'),
            'location_ids' => $locations->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'location_codes' => $locations->pluck('location_code')->values()->all(),
            'status' => (string) $run->status,
            'report' => $this->decodeJson($run->report),
        ];
    }

    public function preflight(string $runId, array $locationIds): array
    {
        $this->assertRequiredTables();
        $shops = Schema::hasTable('channel_shops')
            ? DB::table('channel_shops')->select('id', 'shop_id', 'order_sync_enabled', 'stock_push_enabled', 'fulfillment_push_enabled')->get()
            : collect();

        $report = [
            'database_driver' => DB::getDriverName(),
            'application_environment' => app()->environment(),
            'locations' => DB::table('locations')->whereIn('id', $locationIds)->count(),
            'active_sku_count' => DB::table('product_variants')->where('is_active', true)->count(),
            'orders_without_location' => Schema::hasColumn('sales_orders', 'location_id')
                ? DB::table('sales_orders')->whereNull('location_id')->count()
                : null,
            'channel_shops' => $shops->map(fn (object $shop): array => [
                'shop_id' => $shop->shop_id,
                'order_sync_enabled' => (bool) $shop->order_sync_enabled,
                'stock_push_enabled' => (bool) $shop->stock_push_enabled,
                'fulfillment_push_enabled' => (bool) $shop->fulfillment_push_enabled,
            ])->values()->all(),
            'table_counts' => $this->tableCounts($locationIds),
            'warnings' => [],
        ];

        $report['blocking'] = $report['orders_without_location'] === null || $report['orders_without_location'] > 0 ? 1 : 0;
        if ($report['orders_without_location'] === null) {
            $report['warnings'][] = 'sales_orders_location_id_missing';
        } elseif ($report['orders_without_location'] > 0) {
            $report['warnings'][] = 'orders_without_location_must_be_mapped_before_cutover';
        }

        if (app()->environment('production')) {
            $report['warnings'][] = 'production_environment_requires_explicit_apply_confirmation';
        }

        if ($shops->contains(fn (object $shop): bool => (bool) $shop->stock_push_enabled)) {
            $report['warnings'][] = 'stock_push_is_still_enabled';
        }

        $this->saveReport($runId, 'PREFLIGHT', $report);

        return $report;
    }

    public function auditSku(string $runId, string $manifestPath, array $locationIds): array
    {
        $manifest = $this->readSkuManifest($manifestPath);
        $manifestSkus = array_keys($manifest);
        $master = DB::table('product_variants')
            ->whereIn(DB::raw('LOWER(sku)'), array_map('strtolower', $manifestSkus))
            ->get(['id', 'sku', 'is_active']);
        $masterBySku = $master->keyBy(fn (object $row): string => mb_strtolower(trim((string) $row->sku)));

        $missingMaster = [];
        $inactive = [];
        foreach ($manifestSkus as $sku) {
            $row = $masterBySku->get(mb_strtolower($sku));
            if (! $row) {
                $missingMaster[] = $sku;
            } elseif (! (bool) $row->is_active) {
                $inactive[] = $sku;
            }
        }

        $masterSkus = DB::table('product_variants')->where('is_active', true)->pluck('sku')->map(fn ($sku): string => mb_strtolower(trim((string) $sku)))->all();
        $missingManifest = array_values(array_diff($masterSkus, array_map('mb_strtolower', $manifestSkus)));
        $assignmentIssues = [];
        if (Schema::hasTable('sku_rack_assignments')) {
            $assigned = DB::table('sku_rack_assignments as sra')
                ->join('product_variants as pv', 'pv.id', '=', 'sra.item_id')
                ->join('location_bins as lb', 'lb.id', '=', 'sra.bin_id')
                ->whereIn('sra.location_id', $locationIds)
                ->whereColumn('lb.location_id', 'sra.location_id')
                ->pluck('pv.sku')
                ->map(fn ($sku): string => mb_strtolower(trim((string) $sku)))
                ->all();
            $assignmentIssues = array_values(array_diff(array_map('mb_strtolower', $manifestSkus), $assigned));
        }

        $report = [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => hash_file('sha256', $manifestPath),
            'manifest_sku_count' => count($manifestSkus),
            'master_active_sku_count' => count($masterSkus),
            'missing_master_sku' => $missingMaster,
            'inactive_sku' => $inactive,
            'master_sku_missing_from_manifest' => $missingManifest,
            'rack_assignment_missing' => $assignmentIssues,
            'blocking' => count($missingMaster) + count($inactive) + count($missingManifest) + count($assignmentIssues),
        ];

        $this->saveReport($runId, 'SKU_AUDITED', ['sku_audit' => $report]);

        return $report;
    }

    public function auditStock(string $runId, string $filePath, string $locationCode, bool $advanceStatus = true): array
    {
        $run = $this->getRun($runId);
        $location = DB::table('locations')->where('location_code', $locationCode)->first(['id', 'location_code']);
        if (! $location || ! in_array((string) $location->id, $run['location_ids'], true)) {
            throw new \RuntimeException("lokasi '{$locationCode}' tidak terdaftar pada run_id {$runId}.");
        }

        $rows = $this->readStockRows($filePath);
        $issues = [];
        $pairs = [];
        $total = 0;

        foreach ($rows as $row) {
            $sku = trim((string) $row['sku']);
            $bin = trim((string) $row['bin']);
            $qty = (int) $row['qty'];
            $key = mb_strtolower($sku).'|'.mb_strtolower($bin);
            $total += $qty;

            if ($sku === '' || $bin === '' || $qty < 0) {
                $issues[] = ['row' => $row['row'], 'reason' => 'sku_rak_wajib_dan_qty_tidak_boleh_negatif'];

                continue;
            }
            if (isset($pairs[$key])) {
                $issues[] = ['row' => $row['row'], 'reason' => 'duplikat_sku_rak', 'sku' => $sku, 'bin' => $bin];

                continue;
            }
            $pairs[$key] = true;

            $variant = DB::table('product_variants')->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->first(['id', 'sku', 'is_active']);
            if (! $variant) {
                $issues[] = ['row' => $row['row'], 'reason' => 'sku_tidak_ditemukan', 'sku' => $sku];

                continue;
            }
            if (! (bool) $variant->is_active) {
                $issues[] = ['row' => $row['row'], 'reason' => 'sku_tidak_aktif', 'sku' => $sku];
            }

            $binRow = DB::table('location_bins')->where('location_id', $location->id)->whereRaw('LOWER(bin_final_code) = ?', [mb_strtolower($bin)])->first(['id', 'is_inbound']);
            if (! $binRow) {
                $issues[] = ['row' => $row['row'], 'reason' => 'rak_tidak_ditemukan_di_gudang', 'sku' => $sku, 'bin' => $bin];
            } elseif ((bool) $binRow->is_inbound) {
                $issues[] = ['row' => $row['row'], 'reason' => 'rak_inbound_tidak_boleh_menjadi_stok_awal', 'sku' => $sku, 'bin' => $bin];
            }
        }

        $report = [
            'file_path' => $filePath,
            'sha256' => hash_file('sha256', $filePath),
            'location_code' => $locationCode,
            'row_count' => count($rows),
            'sku_rack_count' => count($pairs),
            'total_qty' => $total,
            'issues' => array_slice($issues, 0, 200),
            'blocking' => count($issues),
        ];

        $currentStatus = DB::table('stock_cutover_runs')->where('id', $runId)->value('status');
        $this->saveReport($runId, 'STOCK_AUDITED', ['stock_audits' => [$locationCode => $report]]);
        if (! $advanceStatus && $currentStatus !== null) {
            DB::table('stock_cutover_runs')->where('id', $runId)->update(['status' => $currentStatus, 'updated_at' => now()]);
        }

        return $report;
    }

    public function importStock(string $runId, string $filePath, string $locationCode, bool $zeroMissing): array
    {
        $run = $this->getRun($runId);
        $this->assertApplyAllowed($run, 'IMPORT-STOCK', ['RESET_APPLIED', 'STOCK_IMPORTED']);
        $sha256 = hash_file('sha256', $filePath);
        $existing = $run['report']['stock_imports'][$locationCode] ?? null;
        if (is_array($existing)) {
            if (($existing['sha256'] ?? null) !== $sha256) {
                throw new \RuntimeException("lokasi {$locationCode} sudah pernah diimport dengan file berbeda, buat run_id baru.");
            }

            return ['location_code' => $locationCode, 'already_applied' => true, 'exit_code' => 0];
        }
        $audit = $this->auditStock($runId, $filePath, $locationCode, false);
        if ((int) $audit['blocking'] > 0) {
            throw new \RuntimeException('import dibatalkan karena stock audit masih memiliki masalah blocking.');
        }

        $exitCode = Artisan::call('inventory:import-baseline', [
            'file' => $filePath,
            '--location' => $locationCode,
            '--commit' => true,
            '--zero-missing' => $zeroMissing,
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException('inventory:import-baseline gagal, tidak ada status STOCK_IMPORTED yang dicatat.');
        }

        $this->saveReport($runId, 'STOCK_IMPORTED', [
            'stock_imports' => [$locationCode => [
                'file_path' => $filePath,
                'sha256' => $sha256,
                'zero_missing' => $zeroMissing,
                'artisan_exit_code' => $exitCode,
            ]],
        ]);

        return ['location_code' => $locationCode, 'exit_code' => $exitCode, 'audit' => $audit];
    }

    public function auditOrders(string $runId): array
    {
        $run = $this->getRun($runId);
        $base = DB::table('sales_orders')->whereIn('location_id', $run['location_ids']);
        $terminal = (clone $base)->where(function ($query): void {
            $query->whereIn('status', self::TERMINAL_STATUSES)->orWhere('is_canceled', true);
        })->where('updated_at', '<', $run['cutoff_at']->utc())->count();
        $active = (clone $base)->where(function ($query): void {
            $query->whereNotIn('status', self::TERMINAL_STATUSES)->where(function ($q): void {
                $q->where('is_canceled', false)->orWhereNull('is_canceled');
            });
        })->count();

        $report = [
            'cutoff_at' => $run['cutoff_at']->toIso8601String(),
            'terminal_before_cutoff' => $terminal,
            'active_orders_to_keep' => $active,
            'terminal_statuses' => self::TERMINAL_STATUSES,
            'cutoff_column' => 'sales_orders.updated_at',
        ];
        $this->saveReport($runId, 'ORDERS_AUDITED', ['order_audit' => $report]);

        return $report;
    }

    public function previewReset(string $runId): array
    {
        $run = $this->getRun($runId);
        $orders = DB::table('sales_orders')->whereIn('location_id', $run['location_ids']);
        $terminal = (clone $orders)->where(function ($query): void {
            $query->whereIn('status', self::TERMINAL_STATUSES)->orWhere('is_canceled', true);
        })->where('updated_at', '<', $run['cutoff_at']->utc())->count();
        $allOrders = (clone $orders)->count();
        $activeKeep = (clone $orders)->whereNotIn('status', self::TERMINAL_STATUSES)->where(function ($query): void {
            $query->where('is_canceled', false)->orWhereNull('is_canceled');
        })->count();

        return [
            'cutoff_at' => $run['cutoff_at']->toIso8601String(),
            'locations' => $run['location_codes'],
            'stock_and_document_rows' => $this->tableCounts($run['location_ids']),
            'orders_total_in_scope' => $allOrders,
            'terminal_orders_to_delete' => $terminal,
            'active_orders_to_keep_and_normalize' => $activeKeep,
            'other_orders_preserved_without_reactivation' => max(0, $allOrders - $terminal - $activeKeep),
            'webhooks_before_cutoff_to_delete' => Schema::hasTable('channel_webhook_inbox')
                ? DB::table('channel_webhook_inbox')->where('received_at', '<', $run['cutoff_at']->utc())->count()
                : 0,
            'finance_rows_delete_only_with_purge_finance' => true,
        ];
    }

    public function reset(string $runId, bool $purgeFinance): array
    {
        $run = $this->getRun($runId);
        $this->assertApplyAllowed($run, 'RESET-STOCK-DATA', ['PAUSED']);
        $counts = [];
        $terminalOrderIds = [];

        DB::transaction(function () use ($run, $purgeFinance, &$counts, &$terminalOrderIds): void {
            $locationIds = $run['location_ids'];
            $this->pauseChannels();

            $orderIds = DB::table('sales_orders')->whereIn('location_id', $locationIds)->pluck('id')->all();
            $terminalOrderIds = DB::table('sales_orders')
                ->whereIn('location_id', $locationIds)
                ->where(function ($query): void {
                    $query->whereIn('status', self::TERMINAL_STATUSES)->orWhere('is_canceled', true);
                })
                ->where('updated_at', '<', $run['cutoff_at']->utc())
                ->pluck('id')->all();

            $this->deleteOperationalDocuments($locationIds, $orderIds, $counts);
            $this->deleteOrderStateHistory($orderIds, $counts);
            $this->deleteReplenishmentRequests($locationIds, $counts);
            $this->deleteInventoryTransfers($locationIds, $counts);
            $this->deleteStockHistory($locationIds, $counts);
            $this->deleteWebhookHistory($run['cutoff_at'], $counts);

            if ($terminalOrderIds !== []) {
                $this->deleteOrderHistory($terminalOrderIds, $purgeFinance, $counts);
            }

            $keepOrderIds = array_values(array_diff($orderIds, $terminalOrderIds));
            if ($keepOrderIds !== []) {
                $this->resetKeptOrders($keepOrderIds);
            }

            DB::table('stock_cutover_runs')->where('id', $run['run_id'])->update([
                'status' => 'RESET_APPLIED',
                'applied_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return ['deleted' => $counts, 'terminal_order_count' => count($terminalOrderIds ?? [])];
    }

    public function previewReservations(string $runId): array
    {
        $run = $this->getRun($runId);
        $this->assertApplyAllowed($run, 'REBUILD-RESERVATION', ['RESET_APPLIED', 'STOCK_IMPORTED', 'RESERVATIONS_REBUILT']);
        $items = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.order_id', '=', 'so.id')
            ->whereIn('so.location_id', $run['location_ids'])
            ->whereIn('so.status', ['pending', 'reserved'])
            ->where(function ($query): void {
                $query->where('so.is_canceled', false)->orWhereNull('so.is_canceled');
            });

        return [
            'eligible_order_count' => (clone $items)->distinct('so.id')->count('so.id'),
            'eligible_order_item_count' => (clone $items)->count(),
            'qty_to_reserve' => (int) (clone $items)->sum('soi.qty_in_base'),
            'current_on_order' => (int) DB::table('inventories')->whereIn('location_id', $run['location_ids'])->sum('on_order'),
            'mode' => 'DRY-RUN',
        ];
    }

    public function rebuildReservations(string $runId): array
    {
        $run = $this->getRun($runId);
        $this->assertApplyAllowed($run, 'REBUILD-RESERVATION', ['RESET_APPLIED', 'STOCK_IMPORTED', 'RESERVATIONS_REBUILT']);
        $applied = 0;
        $skipped = 0;

        DB::transaction(function () use ($run, &$applied, &$skipped): void {
            $locationIds = $run['location_ids'];
            $inventoryIds = DB::table('inventories')->whereIn('location_id', $locationIds)->pluck('id')->all();
            if ($inventoryIds !== []) {
                DB::table('inventories')->whereIn('id', $inventoryIds)->update(['on_order' => 0, 'available' => DB::raw('GREATEST(on_hand, 0)'), 'updated_at' => now()]);
            }

            $orders = DB::table('sales_orders as so')
                ->join('sales_order_items as soi', 'soi.order_id', '=', 'so.id')
                ->join('product_variants as pv', 'pv.id', '=', 'soi.item_id')
                ->whereIn('so.location_id', $locationIds)
                ->whereIn('so.status', ['pending', 'reserved'])
                ->where(function ($query): void {
                    $query->where('so.is_canceled', false)->orWhereNull('so.is_canceled');
                })
                ->select('so.salesorder_no', 'so.location_id', 'soi.item_id', 'soi.sku', 'soi.qty_in_base')
                ->orderBy('so.id')
                ->orderBy('soi.id')
                ->get();

            foreach ($orders as $row) {
                $qty = (int) $row->qty_in_base;
                if ($qty <= 0) {
                    $skipped++;

                    continue;
                }
                $targetBinId = DB::table('sku_rack_assignments')
                    ->where('item_id', $row->item_id)
                    ->where('location_id', $row->location_id)
                    ->value('bin_id');
                $targetBinId ??= DB::table('inventories')
                    ->where('item_id', $row->item_id)
                    ->where('location_id', $row->location_id)
                    ->whereNotNull('bin_id')
                    ->value('bin_id');
                $target = DB::table('inventories')->where('item_id', $row->item_id)->where('location_id', $row->location_id)->where('bin_id', $targetBinId)->first();
                if (! $target) {
                    if ($targetBinId === null) {
                        $skipped++;

                        continue;
                    }
                    $inventoryId = (string) Str::uuid();
                    DB::table('inventories')->insert([
                        'id' => $inventoryId,
                        'item_id' => $row->item_id,
                        'location_id' => $row->location_id,
                        'bin_id' => $targetBinId,
                        'batch_no' => '',
                        'serial_no' => '',
                        'on_hand' => 0,
                        'on_order' => 0,
                        'available' => 0,
                        'avg_cost' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $target = DB::table('inventories')->where('id', $inventoryId)->first();
                }
                DB::table('inventories')->where('id', $target->id)->update([
                    'on_order' => DB::raw('on_order + '.(int) $qty),
                    'available' => DB::raw('GREATEST(on_hand - (on_order + '.(int) $qty.'), 0)'),
                    'updated_at' => now(),
                ]);
                $exists = DB::table('inventory_movements')->where('transaction_number', $row->salesorder_no)->where('item_id', $row->item_id)->where('location_id', $row->location_id)->where('source', 'ORDER_RESERVE')->exists();
                if (! $exists) {
                    DB::table('inventory_movements')->insert([
                        'item_id' => $row->item_id,
                        'location_id' => $row->location_id,
                        'bin_id' => $targetBinId,
                        'transaction_number' => $row->salesorder_no,
                        'source' => 'ORDER_RESERVE',
                        'qty' => $qty,
                        'balance' => (int) $target->on_order + $qty,
                        'transaction_date' => now(),
                        'created_by' => 'cutover',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $applied++;
            }
        });

        DB::table('stock_cutover_runs')->where('id', $runId)->update(['status' => 'RESERVATIONS_REBUILT', 'updated_at' => now()]);

        return compact('applied', 'skipped');
    }

    public function replayOrders(string $runId, int $limit, bool $dryRun): array
    {
        $run = $this->getRun($runId);
        if (! Schema::hasTable('channel_webhook_inbox')) {
            throw new \RuntimeException('tabel channel_webhook_inbox belum tersedia, replay tidak dapat dijalankan.');
        }
        if (! $dryRun) {
            $this->assertApplyAllowed($run, 'REPLAY-ORDERS', ['RESUMED', 'REPLAYED']);
        }
        $query = DB::table('channel_webhook_inbox')
            ->where('received_at', '>=', $run['cutoff_at']->utc())
            ->whereIn('status', ['RECEIVED', 'FAILED', 'received', 'failed'])
            ->orderBy('received_at')
            ->limit(max(1, $limit));
        $records = $query->get();
        if ($dryRun) {
            return ['replayed' => $records->count(), 'failed' => 0];
        }

        $replayed = 0;
        $failed = 0;
        foreach ($records as $record) {
            try {
                Cache::forget((string) $record->event_key);
                DB::table('channel_webhook_inbox')->where('id', $record->id)->update(['status' => 'RECEIVED', 'error' => null, 'updated_at' => now()]);
                $this->dispatchWebhook($record);
                $replayed++;
            } catch (Throwable $exception) {
                DB::table('channel_webhook_inbox')->where('id', $record->id)->update(['status' => 'FAILED', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
                $failed++;
            }
        }

        DB::table('stock_cutover_runs')->where('id', $run['run_id'])->update(['status' => 'REPLAYED', 'updated_at' => now()]);

        return compact('replayed', 'failed');
    }

    public function verify(string $runId): array
    {
        $run = $this->getRun($runId);
        $this->assertApplyAllowed($run, 'VERIFY-CUTOVER', ['RESERVATIONS_REBUILT', 'REPLAYED']);
        $terminalLeft = DB::table('sales_orders')->whereIn('location_id', $run['location_ids'])->where(function ($query): void {
            $query->whereIn('status', self::TERMINAL_STATUSES)->orWhere('is_canceled', true);
        })->where('updated_at', '<', $run['cutoff_at']->utc())->count();
        $ops = $this->tableCounts($run['location_ids']);
        $pushEnabled = Schema::hasTable('channel_shops') && DB::table('channel_shops')->where('stock_push_enabled', true)->exists();
        $negativeOnOrder = DB::table('inventories')->whereIn('location_id', $run['location_ids'])->where('on_order', '<', 0)->count();
        $unexpectedMovementSources = Schema::hasTable('inventory_movements')
            ? DB::table('inventory_movements')
                ->whereIn('location_id', $run['location_ids'])
                ->whereNotIn('source', ['ADJUSTMENT', 'ORDER_RESERVE', 'ORDER_RELEASE'])
                ->select('source')
                ->selectRaw('COUNT(*) as row_count')
                ->groupBy('source')
                ->pluck('row_count', 'source')
                ->map(fn ($count): int => (int) $count)
                ->all()
            : [];
        $report = [
            'terminal_orders_before_cutoff_remaining' => $terminalLeft,
            'stock_push_enabled' => $pushEnabled,
            'negative_on_order_rows' => $negativeOnOrder,
            'unexpected_movement_sources' => $unexpectedMovementSources,
            'table_counts' => $ops,
            'blocking' => $terminalLeft + ($pushEnabled ? 1 : 0) + $negativeOnOrder + array_sum($unexpectedMovementSources),
        ];
        $this->saveReport($runId, $report['blocking'] > 0 ? 'VERIFY_FAILED' : 'VERIFIED', ['verification' => $report]);

        return $report;
    }

    public function pause(string $runId, bool $dryRun): int
    {
        $run = $this->getRun($runId);
        if (! $dryRun) {
            $this->assertApplyAllowed($run, 'PAUSE-CUTOVER', ['ORDERS_AUDITED', 'PAUSED']);
        }
        if (! Schema::hasTable('channel_shops')) {
            return 0;
        }
        $count = DB::table('channel_shops')->where(function ($query): void {
            $query->where('order_sync_enabled', true)->orWhere('stock_push_enabled', true)->orWhere('fulfillment_push_enabled', true);
        })->count();
        if (! $dryRun) {
            DB::table('channel_shops')->update(['order_sync_enabled' => false, 'stock_push_enabled' => false, 'fulfillment_push_enabled' => false, 'updated_at' => now()]);
            DB::table('stock_cutover_runs')->where('id', $run['run_id'])->update(['status' => 'PAUSED', 'updated_at' => now()]);
        }

        return $count;
    }

    public function resume(string $runId, bool $dryRun): int
    {
        $run = $this->getRun($runId);
        if (! $dryRun) {
            $this->assertApplyAllowed($run, 'RESUME-CUTOVER', ['VERIFIED', 'RESUMED', 'REPLAYED']);
        }
        if (! Schema::hasTable('channel_shops')) {
            return 0;
        }
        $count = DB::table('channel_shops')->where('order_sync_enabled', false)->count();
        if (! $dryRun) {
            DB::table('channel_shops')->update(['order_sync_enabled' => true, 'stock_push_enabled' => false, 'fulfillment_push_enabled' => false, 'updated_at' => now()]);
            DB::table('stock_cutover_runs')->where('id', $run['run_id'])->update(['status' => 'RESUMED', 'updated_at' => now()]);
        }

        return $count;
    }

    public function resolveLocations(array $codes): Collection
    {
        $normalized = collect($codes)->map(fn ($code): string => strtoupper(trim((string) $code)))->filter()->unique()->values();
        if ($normalized->isEmpty()) {
            throw new \RuntimeException('--locations wajib berisi allowlist gudang, ALL tidak diizinkan.');
        }
        $locations = DB::table('locations')
            ->whereIn('location_code', $normalized->all())
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->when(Schema::hasColumn('locations', 'is_system'), fn ($query) => $query->where(function ($q): void {
                $q->where('is_system', false)->orWhereNull('is_system');
            }))
            ->get(['id', 'location_code', 'location_name']);
        if ($locations->count() !== $normalized->count()) {
            $missing = $normalized->diff($locations->pluck('location_code'))->implode(', ');
            throw new \RuntimeException("gudang tidak ditemukan atau bukan gudang aktif: {$missing}");
        }

        return $locations;
    }

    private function parseCutoff(string $value): CarbonImmutable
    {
        if (trim($value) === '') {
            throw new \RuntimeException('--cutoff wajib diisi, gunakan format 2026-09-03 18:00:00 WIB atau ISO-8601.');
        }
        try {
            return CarbonImmutable::parse($value, 'Asia/Jakarta');
        } catch (Throwable $e) {
            throw new \RuntimeException('format --cutoff tidak valid.');
        }
    }

    private function assertRequiredTables(): void
    {
        foreach (['locations', 'product_variants', 'inventories', 'sales_orders', 'sales_order_items', 'channel_shops', 'stock_cutover_runs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("tabel wajib '{$table}' belum tersedia, jalankan migration terlebih dahulu.");
            }
        }
    }

    private function assertApplyAllowed(array $run, string $confirmation, array $allowedStatuses): void
    {
        if (! in_array($run['status'], $allowedStatuses, true)) {
            throw new \RuntimeException("run_id {$run['run_id']} berstatus {$run['status']} dan tidak bisa menjalankan {$confirmation} pada tahap ini.");
        }
    }

    private function tableCounts(array $locationIds): array
    {
        $counts = [];
        foreach (array_merge(self::RESET_LOCATION_TABLES, ['inventory_transfers']) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'location_id')) {
                continue;
            }
            $counts[$table] = DB::table($table)->whereIn('location_id', $locationIds)->count();
        }
        if (Schema::hasTable('inventory_transfers')) {
            $counts['inventory_transfers'] = DB::table('inventory_transfers')
                ->where(function ($query) use ($locationIds): void {
                    $query->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds);
                })
                ->count();
        }
        if (Schema::hasTable('stock_replenishment_requests')) {
            $counts['stock_replenishment_requests'] = DB::table('stock_replenishment_requests')
                ->where(function ($query) use ($locationIds): void {
                    $query->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds);
                })
                ->count();
        }
        if (Schema::hasTable('sales_orders')) {
            $counts['sales_orders'] = DB::table('sales_orders')->whereIn('location_id', $locationIds)->count();
        }
        if (Schema::hasTable('channel_webhook_inbox')) {
            $counts['channel_webhook_inbox'] = DB::table('channel_webhook_inbox')->count();
        }

        return $counts;
    }

    private function saveReport(string $runId, string $status, array $report): void
    {
        $run = DB::table('stock_cutover_runs')->where('id', $runId)->first(['report']);
        $existing = $run ? $this->decodeJson($run->report) : [];
        DB::table('stock_cutover_runs')->where('id', $runId)->update([
            'status' => $status,
            'report' => json_encode(array_replace_recursive($existing, $report), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function hashFiles(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }
            if (! is_file($file)) {
                throw new \RuntimeException("file tidak ditemukan: {$file}");
            }
            $result[$file] = hash_file('sha256', $file);
        }

        return $result;
    }

    private function readSkuManifest(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("manifest sku tidak ditemukan: {$path}");
        }
        $rows = $this->readTabular($path);
        $result = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? $row[0] ?? ''));
            if ($sku !== '') {
                $result[$sku] = true;
            }
        }
        if ($result === []) {
            throw new \RuntimeException('manifest sku kosong.');
        }

        return $result;
    }

    private function readStockRows(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("file stok tidak ditemukan: {$path}");
        }
        $rows = $this->readTabular($path);
        $result = [];
        foreach ($rows as $rowNo => $row) {
            $sku = trim((string) ($row['sku'] ?? $row[0] ?? ''));
            $bin = trim((string) ($row['bin'] ?? $row['rack'] ?? $row['no_rak'] ?? $row['kode_rak'] ?? $row['kode_rak_final'] ?? $row[1] ?? ''));
            $qtyValue = $row['qty_actual'] ?? $row['qty_aktual'] ?? $row['qty_on_hand'] ?? $row['qty_on_hand_jubelio'] ?? $row['qty'] ?? $row[2] ?? 0;
            $qty = is_numeric($qtyValue) ? (int) $qtyValue : -1;
            if ($sku === '' && $bin === '' && $qty === 0) {
                continue;
            }
            $result[] = ['row' => (int) ($row['row'] ?? $rowNo + 2), 'sku' => $sku, 'bin' => $bin, 'qty' => $qty];
        }

        return $result;
    }

    private function readTabular(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['csv', 'txt'], true)) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('file tabular tidak dapat dibuka.');
            }
            $first = fgetcsv($handle);
            $header = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $first ?: []);
            $hasHeader = in_array('sku', $header, true) || in_array('qty_actual', $header, true) || in_array('qty_on_hand', $header, true);
            if (! $hasHeader) {
                rewind($handle);
            }
            $result = [];
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                $result[] = $hasHeader ? array_combine($header, array_slice(array_pad($values, count($header), null), 0, count($header))) : $values;
            }
            fclose($handle);

            return $result;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, false);
        if ($raw === []) {
            return [];
        }
        $header = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $raw[0]);
        $hasHeader = in_array('sku', $header, true) || in_array('qty_actual', $header, true) || in_array('qty_on_hand', $header, true);
        $start = $hasHeader ? 1 : 0;
        $result = [];
        for ($i = $start; $i < count($raw); $i++) {
            $values = $raw[$i];
            $result[] = $hasHeader ? array_combine($header, array_slice(array_pad($values, count($header), null), 0, count($header))) : $values;
        }

        return $result;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?: '';

        return trim($header, '_');
    }

    private function pauseChannels(): void
    {
        if (! Schema::hasTable('channel_shops')) {
            return;
        }
        DB::table('channel_shops')->update(['order_sync_enabled' => false, 'stock_push_enabled' => false, 'fulfillment_push_enabled' => false, 'updated_at' => now()]);
    }

    private function deleteOperationalDocuments(array $locationIds, array $orderIds, array &$counts): void
    {
        $childrenByParent = [
            'picklists' => [['picklist_items', 'picklist_id']],
            'packlists' => [['packlist_items', 'packlist_id']],
            'shipments' => [['shipment_tracking_events', 'shipment_id'], ['shipment_orders', 'shipment_id']],
        ];
        foreach ($childrenByParent as $table => $children) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $ids = DB::table($table)->whereIn('location_id', $locationIds)->pluck('id')->all();
            if ($ids === []) {
                continue;
            }
            foreach ($children as [$child, $column]) {
                $this->deleteByParent($child, $column, $ids, $counts);
            }
            $counts[$table] = DB::table($table)->whereIn('id', $ids)->delete();
        }
        if (Schema::hasTable('order_bin_allocations') && $orderIds !== []) {
            $counts['order_bin_allocations'] = DB::table('order_bin_allocations')->whereIn('order_id', $orderIds)->delete();
        }
    }

    private function deleteOrderStateHistory(array $orderIds, array &$counts): void
    {
        if ($orderIds === []) {
            return;
        }
        if (Schema::hasTable('sales_order_status_histories') && Schema::hasColumn('sales_order_status_histories', 'salesorder_id')) {
            $counts['sales_order_status_histories'] = DB::table('sales_order_status_histories')
                ->whereIn('salesorder_id', $orderIds)
                ->delete();
        }
        if (Schema::hasTable('order_buyer_confirmations') && Schema::hasColumn('order_buyer_confirmations', 'order_id')) {
            $counts['order_buyer_confirmations'] = DB::table('order_buyer_confirmations')
                ->whereIn('order_id', $orderIds)
                ->delete();
        }
        if (Schema::hasTable('bulk_shipping_label_items') && Schema::hasColumn('bulk_shipping_label_items', 'order_id')) {
            $batchIds = DB::table('bulk_shipping_label_items')
                ->whereIn('order_id', $orderIds)
                ->pluck('batch_id')
                ->all();
            $counts['bulk_shipping_label_items'] = DB::table('bulk_shipping_label_items')
                ->whereIn('order_id', $orderIds)
                ->delete();
            if (Schema::hasTable('bulk_shipping_label_batches') && $batchIds !== []) {
                $counts['bulk_shipping_label_batches'] = DB::table('bulk_shipping_label_batches as batches')
                    ->whereIn('batches.id', $batchIds)
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('bulk_shipping_label_items')
                            ->whereColumn('bulk_shipping_label_items.batch_id', 'batches.id');
                    })
                    ->delete();
            }
        }
    }

    private function deleteReplenishmentRequests(array $locationIds, array &$counts): void
    {
        if (! Schema::hasTable('stock_replenishment_requests')) {
            return;
        }
        $requestIds = DB::table('stock_replenishment_requests')
            ->where(function ($query) use ($locationIds): void {
                $query->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds);
            })
            ->pluck('id')
            ->all();
        if ($requestIds === []) {
            return;
        }
        if (Schema::hasTable('stock_replenishment_request_items')) {
            $counts['stock_replenishment_request_items'] = DB::table('stock_replenishment_request_items')
                ->whereIn('request_id', $requestIds)
                ->delete();
        }
        $counts['stock_replenishment_requests'] = DB::table('stock_replenishment_requests')
            ->whereIn('id', $requestIds)
            ->delete();
    }

    private function deleteInventoryTransfers(array $locationIds, array &$counts): void
    {
        if (! Schema::hasTable('inventory_transfers')) {
            return;
        }
        $transferIds = DB::table('inventory_transfers')
            ->where(function ($query) use ($locationIds): void {
                $query->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds);
            })
            ->pluck('id')
            ->all();
        if ($transferIds === []) {
            return;
        }
        if (Schema::hasTable('inventory_transfer_items')) {
            $counts['inventory_transfer_items'] = DB::table('inventory_transfer_items')
                ->whereIn('inventory_transfer_id', $transferIds)
                ->delete();
        }
        $counts['inventory_transfers'] = DB::table('inventory_transfers')
            ->whereIn('id', $transferIds)
            ->delete();
    }

    private function deleteStockHistory(array $locationIds, array &$counts): void
    {
        $childrenByParent = [
            'stock_adjustments' => [['stock_adjustment_items', 'stock_adjustment_id']],
            'reserved_stocks' => [['reserved_stock_items', 'reserved_stock_id']],
            'putaways' => [['putaway_items', 'putaway_id'], ['putaway_sources', 'putaway_id']],
            'stock_opnames' => [['stock_opname_items', 'stock_opname_id']],
            'stock_revaluations' => [['stock_revaluation_items', 'stock_revaluation_id']],
            'bin_transfers' => [['bin_transfer_items', 'bin_transfer_id'], ['bin_transfer_receipts', 'bin_transfer_id']],
            'inbounds' => [
                ['inbound_assignments', 'inbound_id'],
                ['inbound_participants', 'inbound_id'],
                ['inbound_items', 'inbound_id'],
                ['putaway_sources', 'inbound_id'],
            ],
            'inbound_receipts' => [],
            'inbound_backfill_reconciliations' => [],
            'picklists' => [['picklist_items', 'picklist_id']],
            'packlists' => [['packlist_items', 'packlist_id']],
            'shipments' => [['shipment_tracking_events', 'shipment_id'], ['shipment_orders', 'shipment_id']],
            'sales_returns' => [['sales_return_items', 'sales_return_id'], ['sales_return_appeals', 'sales_return_id']],
            'bin_transfer_receipts' => [['bin_transfer_receipt_items', 'bin_transfer_receipt_id']],
        ];
        foreach (self::RESET_LOCATION_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'location_id')) {
                continue;
            }
            $ids = DB::table($table)->whereIn('location_id', $locationIds)->pluck('id')->all();
            if ($ids !== []) {
                foreach ($childrenByParent[$table] ?? [] as [$child, $column]) {
                    $this->deleteByParent($child, $column, $ids, $counts);
                }
                $counts[$table] = DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
        if (Schema::hasTable('inventories')) {
            $counts['inventories'] = DB::table('inventories')->whereIn('location_id', $locationIds)->delete();
        }
    }

    private function deleteWebhookHistory(CarbonImmutable $cutoff, array &$counts): void
    {
        if (Schema::hasTable('channel_webhook_inbox')) {
            $counts['channel_webhook_inbox'] = DB::table('channel_webhook_inbox')->where('received_at', '<', $cutoff->utc())->delete();
        }
    }

    private function deleteOrderHistory(array $orderIds, bool $purgeFinance, array &$counts): void
    {
        if ($orderIds === []) {
            return;
        }
        foreach (['sales_order_status_histories', 'order_buyer_confirmations', 'sales_return_items', 'sales_returns', 'warranties', 'bulk_shipping_label_items', 'shipment_orders', 'sales_order_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                $counts[$table] = DB::table($table)->whereIn('order_id', $orderIds)->delete();
            }
        }
        if ($purgeFinance) {
            $invoiceIds = Schema::hasTable('sales_invoices') ? DB::table('sales_invoices')->whereIn('order_id', $orderIds)->pluck('id')->all() : [];
            if ($invoiceIds !== []) {
                if (Schema::hasTable('sales_payments')) {
                    $counts['sales_payments'] = DB::table('sales_payments')->whereIn('sales_invoice_id', $invoiceIds)->delete();
                }
                if (Schema::hasTable('sales_invoice_items')) {
                    $counts['sales_invoice_items'] = DB::table('sales_invoice_items')->whereIn('sales_invoice_id', $invoiceIds)->delete();
                }
                if (Schema::hasTable('sales_invoices')) {
                    $counts['sales_invoices'] = DB::table('sales_invoices')->whereIn('id', $invoiceIds)->delete();
                }
            }
            foreach (['channel_settlement_adjustments', 'sales_return_settlement_invoices', 'sales_return_settlement_refunds'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                    $counts[$table] = DB::table($table)->whereIn('order_id', $orderIds)->delete();
                }
            }
        }
        if (Schema::hasTable('sales_orders')) {
            $counts['sales_orders'] = DB::table('sales_orders')->whereIn('id', $orderIds)->delete();
        }
    }

    private function resetKeptOrders(array $orderIds): void
    {
        $activeOrderIds = DB::table('sales_orders')
            ->whereIn('id', $orderIds)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where(function ($query): void {
                $query->where('is_canceled', false)->orWhereNull('is_canceled');
            })
            ->pluck('id')
            ->all();
        if ($activeOrderIds === []) {
            return;
        }
        $columns = ['updated_at' => now()];
        foreach (['status', 'tracking_number', 'pickup_code', 'handed_to_warehouse_at'] as $column) {
            if (Schema::hasColumn('sales_orders', $column)) {
                $columns[$column] = $column === 'status' ? 'pending' : null;
            }
        }
        if (Schema::hasColumn('sales_orders', 'is_canceled')) {
            $columns['is_canceled'] = false;
        }
        DB::table('sales_orders')->whereIn('id', $activeOrderIds)->update($columns);
    }

    private function deleteByParent(string $table, string $column, array $ids, array &$counts): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $ids === []) {
            return;
        }
        $count = DB::table($table)->whereIn($column, $ids)->delete();
        if ($count > 0) {
            $counts[$table] = ($counts[$table] ?? 0) + $count;
        }
    }

    private function dispatchWebhook(object $record): void
    {
        $payload = is_array($record->payload) ? $record->payload : json_decode((string) $record->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \RuntimeException('payload webhook tidak valid.');
        }
        match (strtolower((string) $record->channel)) {
            'shopee' => ProcessShopeeWebhook::dispatch($payload),
            'tiktok' => ProcessTikTokWebhook::dispatch($payload),
            'lazada' => ProcessLazadaWebhook::dispatch($payload),
            'woocommerce' => $this->dispatchWooCommerceWebhook($record, $payload),
            default => throw new \RuntimeException("channel webhook tidak didukung: {$record->channel}"),
        };
    }

    private function dispatchWooCommerceWebhook(object $record, array $payload): void
    {
        $shopId = trim((string) ($record->shop_id ?? ''));
        $topic = trim((string) ($record->event_type ?? ''));
        $resourceId = trim((string) ($payload['id'] ?? ''));
        if ($shopId === '' || $topic === '' || $resourceId === '') {
            throw new \RuntimeException('data webhook WooCommerce tidak lengkap untuk replay (shop_id, event_type, atau payload.id kosong).');
        }
        ProcessWooCommerceWebhook::dispatch($shopId, $topic, $resourceId, $payload);
    }
}
