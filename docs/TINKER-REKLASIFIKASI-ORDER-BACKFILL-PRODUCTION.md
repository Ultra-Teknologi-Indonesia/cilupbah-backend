# Reklasifikasi Order Backfill dari DEFAULT ke Rak Alokasi

Runbook ini hanya untuk memperbaiki mutasi historis `system:backfill` yang salah memakai bin inbound/`DEFAULT`.

Prinsip yang dijaga:

- stok Gudang Pusat tidak disentuh;
- `on_order` tidak diubah;
- total `on_hand` per SKU/lokasi tidak berubah;
- stok rak Gudang Kecil boleh menjadi negatif sesuai brief operasional;
- `ORDER_COMPLETE_OUT` dan `ORDER_COMPLETE_REVERSAL` dipindahkan bersama;
- setiap source movement hanya dapat diproses sekali;
- proses apply dilakukan maksimal 1.000 movement per eksekusi agar tidak OOM;
- satu kegagalan tak terduga menghentikan batch, sedangkan baris sebelumnya tetap aman dan idempoten.

Jangan memakai `inventory:reconcile-inbound-backfill --apply` untuk kasus ini. Implementasi command tersebut masih menolak rak yang stoknya tidak cukup dan belum mereklasifikasi reversal dengan strategi yang dipakai runbook ini.

## 1. Dry-run final seluruh movement

Perintah ini hanya membaca database. Hasil aman wajib memiliki `blocked_rows=0`, `group_mismatch=0`, `net_location_delta=0`, dan `READY_TO_APPLY=YES`.

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
(function () {
    set_time_limit(0);
    DB::connection()->disableQueryLog();

    if (!\Illuminate\Support\Facades\Schema::hasTable('inbound_backfill_reconciliations')) {
        echo "ABORT: tabel inbound_backfill_reconciliations belum tersedia\n";
        return;
    }

    $smallWarehouseId = \Modules\Warehouse\Models\Location::getOfficialSmallWarehouseId();
    $smallWarehouse = $smallWarehouseId
        ? DB::table('locations')->where('id', $smallWarehouseId)->first(['id', 'location_name'])
        : null;
    if (!$smallWarehouse || $smallWarehouse->location_name !== 'Gudang Kecil') {
        echo "ABORT: Gudang Kecil resmi tidak ditemukan atau identitas lokasi berubah\n";
        return;
    }

    $summary = [
        'source_rows' => 0,
        'out_rows' => 0,
        'out_qty' => 0,
        'reversal_rows' => 0,
        'reversal_qty' => 0,
        'blocked_rows' => 0,
        'groups' => 0,
        'group_mismatch' => 0,
        'default_delta' => 0,
        'target_delta' => 0,
    ];
    $groups = [];

    $candidateQuery = function () use ($smallWarehouseId) {
        return DB::table('inventory_movements as im')
            ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
            ->join('product_variants as pv', 'pv.id', '=', 'im.item_id')
            ->join('locations as l', 'l.id', '=', 'im.location_id')
            ->where('inbound_bin.is_inbound', true)
            ->where('im.location_id', $smallWarehouseId)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('inbound_backfill_reconciliations as reconciliation')
                    ->whereColumn('reconciliation.source_movement_id', 'im.id');
            })
            ->where(function ($query) {
                $query->where(function ($out) {
                    $out->where('im.source', 'ORDER_COMPLETE_OUT')
                        ->where('im.created_by', 'system:backfill')
                        ->where('im.qty', '<', 0);
                })->orWhere(function ($reversal) {
                    $reversal->where('im.source', 'ORDER_COMPLETE_REVERSAL')
                        ->where('im.qty', '>', 0)
                        ->whereExists(function ($original) {
                            $original->selectRaw('1')
                                ->from('inventory_movements as original')
                                ->whereColumn('original.transaction_number', 'im.transaction_number')
                                ->whereColumn('original.item_id', 'im.item_id')
                                ->whereColumn('original.location_id', 'im.location_id')
                                ->whereColumn('original.bin_id', 'im.bin_id')
                                ->where('original.source', 'ORDER_COMPLETE_OUT')
                                ->where('original.created_by', 'system:backfill')
                                ->where('original.qty', '<', 0);
                        });
                });
            })
            ->select([
                'im.id',
                'im.item_id',
                'im.location_id',
                'im.bin_id as inbound_bin_id',
                'im.source',
                'im.qty',
                'im.transaction_number',
                'pv.sku',
                'l.location_name',
            ])
            ->selectSub(function ($query) {
                $query->from('sku_rack_assignments as assignment_count')
                    ->whereColumn('assignment_count.item_id', 'im.item_id')
                    ->whereColumn('assignment_count.location_id', 'im.location_id')
                    ->selectRaw('COUNT(*)');
            }, 'target_count')
            ->selectSub(function ($query) {
                $query->from('sku_rack_assignments as assignment_target')
                    ->join('location_bins as target', function ($join) {
                        $join->on('target.id', '=', 'assignment_target.bin_id')
                            ->where('target.is_inbound', false);
                    })
                    ->whereColumn('assignment_target.item_id', 'im.item_id')
                    ->whereColumn('assignment_target.location_id', 'im.location_id')
                    ->orderBy('assignment_target.id')
                    ->limit(1)
                    ->select('assignment_target.bin_id');
            }, 'target_bin_id')
            ->selectSub(function ($query) {
                $query->from('sku_rack_assignments as assignment_target')
                    ->join('location_bins as target', function ($join) {
                        $join->on('target.id', '=', 'assignment_target.bin_id')
                            ->where('target.is_inbound', false);
                    })
                    ->whereColumn('assignment_target.item_id', 'im.item_id')
                    ->whereColumn('assignment_target.location_id', 'im.location_id')
                    ->orderBy('assignment_target.id')
                    ->limit(1)
                    ->select('target.bin_final_code');
            }, 'target_bin_code')
            ->orderBy('im.id');
    };

    echo "MODE=DRY-RUN / READ-ONLY\n";
    echo "SCOPE=OUT + REVERSAL YANG BELUM DIREKLASIFIKASI\n";
    echo "LOCATION={$smallWarehouse->location_name} ({$smallWarehouseId})\n\n";

    DB::beginTransaction();
    try {
        DB::statement('SET TRANSACTION READ ONLY');

        foreach ($candidateQuery()->lazy(500) as $row) {
            $summary['source_rows']++;
            $qty = abs((int) $row->qty);
            $isOut = $row->source === 'ORDER_COMPLETE_OUT';

            if ($isOut) {
                $summary['out_rows']++;
                $summary['out_qty'] += $qty;
            } else {
                $summary['reversal_rows']++;
                $summary['reversal_qty'] += $qty;
            }

            if ((int) $row->target_count !== 1 || !$row->target_bin_id) {
                $summary['blocked_rows']++;
                echo "BLOCKED sku={$row->sku} location={$row->location_name} source={$row->source} trx={$row->transaction_number} valid_target_count={$row->target_count} reason=TARGET_RACK_MUST_BE_EXACTLY_ONE\n";
                continue;
            }

            $defaultDelta = $isOut ? $qty : -$qty;
            $targetDelta = -$defaultDelta;
            $summary['default_delta'] += $defaultDelta;
            $summary['target_delta'] += $targetDelta;

            $key = implode('|', [
                $row->item_id,
                $row->location_id,
                $row->inbound_bin_id,
                $row->target_bin_id,
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'item_id' => $row->item_id,
                    'location_id' => $row->location_id,
                    'inbound_bin_id' => $row->inbound_bin_id,
                    'target_bin_id' => $row->target_bin_id,
                    'sku' => $row->sku,
                    'location_name' => $row->location_name,
                    'target_bin_code' => $row->target_bin_code,
                    'default_delta' => 0,
                    'target_delta' => 0,
                ];
            }

            $groups[$key]['default_delta'] += $defaultDelta;
            $groups[$key]['target_delta'] += $targetDelta;

            if ($summary['source_rows'] % 1000 === 0) {
                echo "PROGRESS source_rows={$summary['source_rows']}\n";
                gc_collect_cycles();
            }
        }

        $summary['groups'] = count($groups);

        foreach ($groups as $group) {
            $defaultBefore = (int) DB::table('inventories')
                ->where('item_id', $group['item_id'])
                ->where('location_id', $group['location_id'])
                ->where('bin_id', $group['inbound_bin_id'])
                ->sum('on_hand');
            $defaultAfter = $defaultBefore + $group['default_delta'];

            $canonicalPending = (int) DB::table('inbound_items as ii')
                ->join('inbounds as ib', 'ib.id', '=', 'ii.inbound_id')
                ->where('ii.item_id', $group['item_id'])
                ->where('ib.location_id', $group['location_id'])
                ->whereNotIn('ib.status', ['CANCELLED', 'CANCELED'])
                ->selectRaw('COALESCE(SUM(GREATEST(ii.received_qty-ii.putaway_qty,0)),0) AS pending')
                ->value('pending');

            if ($defaultAfter !== $canonicalPending) {
                $summary['group_mismatch']++;
                echo "MISMATCH sku={$group['sku']} location={$group['location_name']} target={$group['target_bin_code']} default_after={$defaultAfter} canonical_pending={$canonicalPending}\n";
            }
        }

        echo "\nSUMMARY\n";
        foreach ($summary as $key => $value) {
            echo "{$key}={$value}\n";
        }

        $netLocationDelta = $summary['default_delta'] + $summary['target_delta'];
        echo "net_location_delta={$netLocationDelta}\n";
        $safe = $summary['source_rows'] > 0
            && $summary['blocked_rows'] === 0
            && $summary['group_mismatch'] === 0
            && $netLocationDelta === 0;
        echo 'READY_TO_APPLY='.($safe ? 'YES' : 'NO')."\n";
    } finally {
        DB::rollBack();
    }

    echo "DATABASE TIDAK DIUBAH\n";
})();
PHP
```

Dengan snapshot audit terakhir, angka yang diharapkan sebelum ada transaksi baru adalah:

```text
source_rows=19939
out_rows=19819
out_qty=20208
reversal_rows=120
reversal_qty=121
groups=198
blocked_rows=0
group_mismatch=0
net_location_delta=0
READY_TO_APPLY=YES
```

Angka transaksi boleh bertambah jika ada aktivitas baru. Empat kriteria terakhir wajib tetap sesuai.

## 2. Apply satu batch maksimal 1.000 movement

Jalankan hanya setelah langkah 1 menghasilkan `READY_TO_APPLY=YES`.

Script ini sengaja tidak memanggil notifikasi stok minus agar proses repair tidak membanjiri notifikasi operasional. Setiap movement diproses dalam transaksi tersendiri dan dicatat di `inbound_backfill_reconciliations`.

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
(function () {
    set_time_limit(0);
    DB::connection()->disableQueryLog();

    $confirmation = 'RECLASSIFY-BACKFILL-ORDER-TO-ASSIGNED-RACK';
    $requiredConfirmation = 'RECLASSIFY-BACKFILL-ORDER-TO-ASSIGNED-RACK';
    $batchLimit = 1000;

    if ($confirmation !== $requiredConfirmation) {
        echo "ABORT: confirmation salah\n";
        return;
    }
    if (!\Illuminate\Support\Facades\Schema::hasTable('inbound_backfill_reconciliations')) {
        echo "ABORT: tabel inbound_backfill_reconciliations belum tersedia\n";
        return;
    }

    $smallWarehouseId = \Modules\Warehouse\Models\Location::getOfficialSmallWarehouseId();
    $smallWarehouse = $smallWarehouseId
        ? DB::table('locations')->where('id', $smallWarehouseId)->first(['id', 'location_name'])
        : null;
    if (!$smallWarehouse || $smallWarehouse->location_name !== 'Gudang Kecil') {
        echo "ABORT: Gudang Kecil resmi tidak ditemukan atau identitas lokasi berubah\n";
        return;
    }

    $candidateQuery = function () use ($smallWarehouseId) {
        return DB::table('inventory_movements as im')
            ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
            ->where('inbound_bin.is_inbound', true)
            ->where('im.location_id', $smallWarehouseId)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('inbound_backfill_reconciliations as reconciliation')
                    ->whereColumn('reconciliation.source_movement_id', 'im.id');
            })
            ->where(function ($query) {
                $query->where(function ($out) {
                    $out->where('im.source', 'ORDER_COMPLETE_OUT')
                        ->where('im.created_by', 'system:backfill')
                        ->where('im.qty', '<', 0);
                })->orWhere(function ($reversal) {
                    $reversal->where('im.source', 'ORDER_COMPLETE_REVERSAL')
                        ->where('im.qty', '>', 0)
                        ->whereExists(function ($original) {
                            $original->selectRaw('1')
                                ->from('inventory_movements as original')
                                ->whereColumn('original.transaction_number', 'im.transaction_number')
                                ->whereColumn('original.item_id', 'im.item_id')
                                ->whereColumn('original.location_id', 'im.location_id')
                                ->whereColumn('original.bin_id', 'im.bin_id')
                                ->where('original.source', 'ORDER_COMPLETE_OUT')
                                ->where('original.created_by', 'system:backfill')
                                ->where('original.qty', '<', 0);
                        });
                });
            })
            ->select('im.id')
            ->orderBy('im.id');
    };

    $ids = $candidateQuery()->limit($batchLimit)->pluck('im.id');
    $runId = (string) \Illuminate\Support\Str::uuid();
    $applied = 0;
    $alreadyReconciled = 0;
    $failed = 0;

    echo "MODE=APPLY BATCH\n";
    echo "location={$smallWarehouse->location_name} ({$smallWarehouseId})\n";
    echo "run_id={$runId}\n";
    echo "batch_limit={$batchLimit}\n";
    echo "selected_rows={$ids->count()}\n";

    foreach ($ids as $sourceMovementId) {
        try {
            $outcome = DB::transaction(function () use ($sourceMovementId, $runId, $smallWarehouseId) {
                if (DB::table('inbound_backfill_reconciliations')
                    ->where('source_movement_id', $sourceMovementId)
                    ->exists()) {
                    return 'already';
                }

                $source = DB::table('inventory_movements')
                    ->where('id', $sourceMovementId)
                    ->lockForUpdate()
                    ->first();
                if (!$source) {
                    throw new RuntimeException('Source movement tidak ditemukan');
                }
                if ($source->location_id !== $smallWarehouseId) {
                    throw new RuntimeException('Source movement bukan milik Gudang Kecil');
                }

                $inboundBin = Modules\Warehouse\Models\LocationBin::where('id', $source->bin_id)
                    ->where('location_id', $source->location_id)
                    ->lockForUpdate()
                    ->first();
                if (!$inboundBin || !(bool) $inboundBin->is_inbound) {
                    throw new RuntimeException('Source bin bukan bin inbound');
                }

                $isOut = $source->source === 'ORDER_COMPLETE_OUT'
                    && $source->created_by === 'system:backfill'
                    && (int) $source->qty < 0;
                $isReversal = $source->source === 'ORDER_COMPLETE_REVERSAL'
                    && (int) $source->qty > 0;
                if (!$isOut && !$isReversal) {
                    throw new RuntimeException('Source movement tidak lagi memenuhi syarat');
                }

                if ($isReversal) {
                    $originalExists = DB::table('inventory_movements')
                        ->where('transaction_number', $source->transaction_number)
                        ->where('item_id', $source->item_id)
                        ->where('location_id', $source->location_id)
                        ->where('bin_id', $source->bin_id)
                        ->where('source', 'ORDER_COMPLETE_OUT')
                        ->where('created_by', 'system:backfill')
                        ->where('qty', '<', 0)
                        ->exists();
                    if (!$originalExists) {
                        throw new RuntimeException('Reversal tidak memiliki original backfill OUT');
                    }
                }

                $assignments = Modules\Inventory\Models\SkuRackAssignment::where('item_id', $source->item_id)
                    ->where('location_id', $source->location_id)
                    ->lockForUpdate()
                    ->get();
                if ($assignments->count() !== 1) {
                    throw new RuntimeException('Assignment rak harus tepat satu');
                }

                $targetBin = Modules\Warehouse\Models\LocationBin::where('id', $assignments->first()->bin_id)
                    ->where('location_id', $source->location_id)
                    ->lockForUpdate()
                    ->first();
                if (!$targetBin || (bool) $targetBin->is_inbound) {
                    throw new RuntimeException('Rak tujuan tidak valid');
                }

                $inventoryRepository = app(Modules\Inventory\Repositories\InventoryRepository::class);
                $binIds = [$source->bin_id, $targetBin->id];
                sort($binIds, SORT_STRING);
                $locked = [];
                foreach ($binIds as $binId) {
                    $locked[$binId] = $inventoryRepository->findOrCreateForUpdate(
                        $source->item_id,
                        $source->location_id,
                        $binId
                    );
                }

                $defaultInventory = $locked[$source->bin_id];
                $targetInventory = $locked[$targetBin->id];
                $pairOnHandBefore = (int) $defaultInventory->on_hand + (int) $targetInventory->on_hand;
                $pairOnOrderBefore = (int) $defaultInventory->on_order + (int) $targetInventory->on_order;

                $qty = abs((int) $source->qty);
                $defaultDelta = $isOut ? $qty : -$qty;
                $targetDelta = -$defaultDelta;

                $defaultInventory->on_hand = (int) $defaultInventory->on_hand + $defaultDelta;
                $targetInventory->on_hand = (int) $targetInventory->on_hand + $targetDelta;
                if (!$inventoryRepository->updateStock($defaultInventory)) {
                    throw new RuntimeException('Gagal memperbarui inventory DEFAULT');
                }
                if (!$inventoryRepository->updateStock($targetInventory)) {
                    throw new RuntimeException('Gagal memperbarui inventory rak tujuan');
                }

                $defaultBalance = (int) Modules\Inventory\Models\Inventory::where('item_id', $source->item_id)
                    ->where('location_id', $source->location_id)
                    ->where('bin_id', $source->bin_id)
                    ->sum('on_hand');
                $targetBalance = (int) Modules\Inventory\Models\Inventory::where('item_id', $source->item_id)
                    ->where('location_id', $source->location_id)
                    ->where('bin_id', $targetBin->id)
                    ->sum('on_hand');

                $costPerUnit = $source->cost_per_unit !== null ? (float) $source->cost_per_unit : null;
                $correctionNumber = substr((string) $source->transaction_number, 0, 70)
                    .'-RECLASS-'.substr((string) $source->id, -8);
                Modules\Inventory\Models\InventoryMovement::create([
                    'item_id' => $source->item_id,
                    'location_id' => $source->location_id,
                    'bin_id' => $source->bin_id,
                    'transaction_number' => $correctionNumber,
                    'source' => 'BACKFILL_INBOUND_RESTORE',
                    'qty' => $defaultDelta,
                    'balance' => $defaultBalance,
                    'cost_per_unit' => $costPerUnit,
                    'total_cost' => $costPerUnit === null ? null : $defaultDelta * $costPerUnit,
                    'transaction_date' => $source->transaction_date,
                    'created_by' => 'system:inbound-backfill-reconcile',
                ]);
                Modules\Inventory\Models\InventoryMovement::create([
                    'item_id' => $source->item_id,
                    'location_id' => $source->location_id,
                    'bin_id' => $targetBin->id,
                    'transaction_number' => $source->transaction_number,
                    'source' => $source->source,
                    'qty' => $targetDelta,
                    'balance' => $targetBalance,
                    'cost_per_unit' => $costPerUnit,
                    'total_cost' => $costPerUnit === null ? null : $targetDelta * $costPerUnit,
                    'transaction_date' => $source->transaction_date,
                    'created_by' => 'system:inbound-backfill-reconcile',
                ]);

                if ($isOut) {
                    $orderId = DB::table('sales_orders')
                        ->where('salesorder_no', $source->transaction_number)
                        ->value('id');
                    if ($orderId) {
                        DB::table('order_bin_allocations')
                            ->where('order_id', $orderId)
                            ->where('item_id', $source->item_id)
                            ->where('location_id', $source->location_id)
                            ->where('bin_id', $source->bin_id)
                            ->update([
                                'bin_id' => $targetBin->id,
                                'updated_at' => now(),
                            ]);
                    }
                }

                DB::table('inbound_backfill_reconciliations')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'source_movement_id' => $source->id,
                    'item_id' => $source->item_id,
                    'location_id' => $source->location_id,
                    'inbound_bin_id' => $source->bin_id,
                    'target_bin_id' => $targetBin->id,
                    'qty' => $qty,
                    'strategy' => $isOut
                        ? 'SKU_RACK_ASSIGNMENT_OUT'
                        : 'SKU_RACK_ASSIGNMENT_REVERSAL',
                    'run_id' => $runId,
                    'applied_by' => 'system:tinker-reclassify-backfill',
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $pairOnHandAfter = (int) $defaultInventory->fresh()->on_hand
                    + (int) $targetInventory->fresh()->on_hand;
                $pairOnOrderAfter = (int) $defaultInventory->fresh()->on_order
                    + (int) $targetInventory->fresh()->on_order;
                if ($pairOnHandAfter !== $pairOnHandBefore) {
                    throw new RuntimeException('Total on_hand pasangan bin berubah');
                }
                if ($pairOnOrderAfter !== $pairOnOrderBefore) {
                    throw new RuntimeException('on_order ikut berubah');
                }

                return 'applied';
            }, 3);

            if ($outcome === 'applied') {
                $applied++;
            } else {
                $alreadyReconciled++;
            }
        } catch (Throwable $exception) {
            $failed++;
            echo "FAILED source_movement_id={$sourceMovementId} message="
                .preg_replace('/\s+/', ' ', $exception->getMessage())."\n";
            break;
        }

        if (($applied + $alreadyReconciled) % 100 === 0) {
            echo "PROGRESS applied={$applied} already={$alreadyReconciled}\n";
            gc_collect_cycles();
        }
    }

    $remaining = $candidateQuery()->count();
    echo "\nBATCH SUMMARY\n";
    echo "applied={$applied}\n";
    echo "already_reconciled={$alreadyReconciled}\n";
    echo "failed={$failed}\n";
    echo "remaining={$remaining}\n";
    echo $failed === 0
        ? "BATCH_RESULT=SUCCESS\n"
        : "BATCH_RESULT=STOP_AND_REVIEW\n";
})();
PHP
```

Kriteria setiap batch:

```text
failed=0
BATCH_RESULT=SUCCESS
```

Jika `remaining` masih lebih dari nol, jalankan ulang perintah apply yang sama. Jangan mengubah `$confirmation` atau `$batchLimit`. Berhenti ketika:

```text
remaining=0
```

Jika muncul `FAILED`, jangan langsung mengulang. Simpan `source_movement_id` dan pesan error untuk diperiksa.

## 3. Normalisasi `available` setelah remaining=0

Langkah ini hanya menyelaraskan field turunan `available`. Rumusnya tetap mengikuti kode aplikasi: bin inbound/`DEFAULT` dan `bin_id=NULL` bernilai `0`, sedangkan rak aktif bernilai `on_hand-on_order`. Nilai `on_hand` dan `on_order` tidak diubah.

### 3.1 Dry-run drift `available`

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
(function () {
    DB::connection()->disableQueryLog();

    $smallWarehouseId = \Modules\Warehouse\Models\Location::getOfficialSmallWarehouseId();
    $smallWarehouse = $smallWarehouseId
        ? DB::table('locations')->where('id', $smallWarehouseId)->first(['id', 'location_name'])
        : null;
    if (!$smallWarehouse || $smallWarehouse->location_name !== 'Gudang Kecil') {
        echo "ABORT: Gudang Kecil resmi tidak ditemukan atau identitas lokasi berubah\n";
        return;
    }

    $driftQuery = DB::table('inventories as inventory')
        ->leftJoin('location_bins as bin', 'bin.id', '=', 'inventory.bin_id')
        ->where('inventory.location_id', $smallWarehouseId)
        ->whereExists(function ($query) {
            $query->selectRaw('1')
                ->from('inbound_backfill_reconciliations as reconciliation')
                ->whereColumn('reconciliation.item_id', 'inventory.item_id')
                ->whereColumn('reconciliation.location_id', 'inventory.location_id');
        })
        ->whereRaw('inventory.available <> CASE WHEN bin.is_inbound IS TRUE OR inventory.bin_id IS NULL THEN 0 ELSE inventory.on_hand-inventory.on_order END');

    echo "MODE=DRY-RUN / READ-ONLY\n";
    echo "LOCATION={$smallWarehouse->location_name} ({$smallWarehouseId})\n";
    echo 'available_drift_rows='.$driftQuery->count()."\n";

    foreach ((clone $driftQuery)
        ->join('product_variants as pv', 'pv.id', '=', 'inventory.item_id')
        ->select([
            'pv.sku',
            'bin.bin_final_code',
            'inventory.on_hand',
            'inventory.on_order',
            'inventory.available',
            'bin.is_inbound',
        ])
        ->limit(30)
        ->get() as $row) {
        $expected = $row->is_inbound === null || (bool) $row->is_inbound
            ? 0
            : (int) $row->on_hand - (int) $row->on_order;
        echo "sku={$row->sku} bin=".($row->bin_final_code ?? '-')." on_hand={$row->on_hand} on_order={$row->on_order} stored_available={$row->available} expected_available={$expected}\n";
    }

    echo "DATABASE TIDAK DIUBAH\n";
})();
PHP
```

Jika `available_drift_rows=0`, lewati langkah 3.2. Jika lebih dari nol, jalankan apply berikut per batch.

### 3.2 Apply normalisasi maksimal 1.000 baris

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
(function () {
    DB::connection()->disableQueryLog();

    $confirmation = 'NORMALIZE-AFFECTED-INVENTORY-AVAILABLE';
    $requiredConfirmation = 'NORMALIZE-AFFECTED-INVENTORY-AVAILABLE';
    $batchLimit = 1000;
    if ($confirmation !== $requiredConfirmation) {
        echo "ABORT: confirmation salah\n";
        return;
    }

    $smallWarehouseId = \Modules\Warehouse\Models\Location::getOfficialSmallWarehouseId();
    $smallWarehouse = $smallWarehouseId
        ? DB::table('locations')->where('id', $smallWarehouseId)->first(['id', 'location_name'])
        : null;
    if (!$smallWarehouse || $smallWarehouse->location_name !== 'Gudang Kecil') {
        echo "ABORT: Gudang Kecil resmi tidak ditemukan atau identitas lokasi berubah\n";
        return;
    }

    $driftQuery = function () use ($smallWarehouseId) {
        return DB::table('inventories as inventory')
            ->leftJoin('location_bins as bin', 'bin.id', '=', 'inventory.bin_id')
            ->where('inventory.location_id', $smallWarehouseId)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('inbound_backfill_reconciliations as reconciliation')
                    ->whereColumn('reconciliation.item_id', 'inventory.item_id')
                    ->whereColumn('reconciliation.location_id', 'inventory.location_id');
            })
            ->whereRaw('inventory.available <> CASE WHEN bin.is_inbound IS TRUE OR inventory.bin_id IS NULL THEN 0 ELSE inventory.on_hand-inventory.on_order END');
    };

    $ids = $driftQuery()->orderBy('inventory.id')->limit($batchLimit)->pluck('inventory.id');
    $updated = 0;
    $unchanged = 0;
    $failed = 0;

    echo "MODE=APPLY AVAILABLE NORMALIZATION\n";
    echo "location={$smallWarehouse->location_name} ({$smallWarehouseId})\n";
    echo "selected_rows={$ids->count()}\n";

    foreach ($ids as $inventoryId) {
        try {
            $outcome = DB::transaction(function () use ($inventoryId, $smallWarehouseId) {
                $inventory = \Modules\Inventory\Models\Inventory::where('id', $inventoryId)
                    ->lockForUpdate()
                    ->first();
                if (!$inventory) {
                    throw new RuntimeException('Inventory tidak ditemukan');
                }
                if ($inventory->location_id !== $smallWarehouseId) {
                    throw new RuntimeException('Inventory bukan milik Gudang Kecil');
                }

                $isInbound = $inventory->bin_id === null
                    ? null
                    : \Modules\Warehouse\Models\LocationBin::where('id', $inventory->bin_id)
                        ->where('location_id', $smallWarehouseId)
                        ->value('is_inbound');
                $expected = $inventory->bin_id === null || (bool) $isInbound
                    ? 0
                    : (int) $inventory->on_hand - (int) $inventory->on_order;
                if ((int) $inventory->available === $expected) {
                    return 'unchanged';
                }

                $onHandBefore = (int) $inventory->on_hand;
                $onOrderBefore = (int) $inventory->on_order;
                $inventory->available = $expected;
                $inventory->save();
                $inventory->refresh();
                if ((int) $inventory->on_hand !== $onHandBefore || (int) $inventory->on_order !== $onOrderBefore) {
                    throw new RuntimeException('on_hand atau on_order ikut berubah');
                }

                return 'updated';
            }, 3);

            $outcome === 'updated' ? $updated++ : $unchanged++;
        } catch (Throwable $exception) {
            $failed++;
            echo "FAILED inventory_id={$inventoryId} message="
                .preg_replace('/\s+/', ' ', $exception->getMessage())."\n";
            break;
        }
    }

    $remaining = $driftQuery()->count();
    echo "updated={$updated}\n";
    echo "unchanged={$unchanged}\n";
    echo "failed={$failed}\n";
    echo "remaining={$remaining}\n";
    echo $failed === 0 ? "NORMALIZE_RESULT=SUCCESS\n" : "NORMALIZE_RESULT=STOP_AND_REVIEW\n";
})();
PHP
```

Kriteria setiap batch adalah `failed=0` dan `NORMALIZE_RESULT=SUCCESS`. Ulangi perintah yang sama sampai `remaining=0`, lalu jalankan kembali dry-run 3.1 dan pastikan `available_drift_rows=0`.

## 4. Post-audit setelah remaining=0

Perintah ini memeriksa kelengkapan reconciliation, pasangan movement koreksi, konsistensi DEFAULT dengan penerimaan aktif, dan drift `available`.

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
(function () {
    set_time_limit(0);
    DB::connection()->disableQueryLog();

    $summary = [
        'source_rows' => 0,
        'reconciled_rows' => 0,
        'missing_reconciliation' => 0,
        'invalid_reconciliation_qty' => 0,
        'missing_default_correction' => 0,
        'missing_target_movement' => 0,
        'group_default_mismatch' => 0,
        'available_drift_rows' => 0,
        'allocation_still_default' => 0,
    ];

    $smallWarehouseId = \Modules\Warehouse\Models\Location::getOfficialSmallWarehouseId();
    $smallWarehouse = $smallWarehouseId
        ? DB::table('locations')->where('id', $smallWarehouseId)->first(['id', 'location_name'])
        : null;
    if (!$smallWarehouse || $smallWarehouse->location_name !== 'Gudang Kecil') {
        echo "ABORT: Gudang Kecil resmi tidak ditemukan atau identitas lokasi berubah\n";
        return;
    }

    $sourceQuery = function () use ($smallWarehouseId) {
        return DB::table('inventory_movements as im')
            ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
            ->where('inbound_bin.is_inbound', true)
            ->where('im.location_id', $smallWarehouseId)
            ->where(function ($query) {
                $query->where(function ($out) {
                    $out->where('im.source', 'ORDER_COMPLETE_OUT')
                        ->where('im.created_by', 'system:backfill')
                        ->where('im.qty', '<', 0);
                })->orWhere(function ($reversal) {
                    $reversal->where('im.source', 'ORDER_COMPLETE_REVERSAL')
                        ->where('im.qty', '>', 0)
                        ->whereExists(function ($original) {
                            $original->selectRaw('1')
                                ->from('inventory_movements as original')
                                ->whereColumn('original.transaction_number', 'im.transaction_number')
                                ->whereColumn('original.item_id', 'im.item_id')
                                ->whereColumn('original.location_id', 'im.location_id')
                                ->whereColumn('original.bin_id', 'im.bin_id')
                                ->where('original.source', 'ORDER_COMPLETE_OUT')
                                ->where('original.created_by', 'system:backfill')
                                ->where('original.qty', '<', 0);
                        });
                });
            })
            ->select('im.*')
            ->orderBy('im.id');
    };

    echo "MODE=POST-AUDIT / READ-ONLY\n";
    echo "LOCATION={$smallWarehouse->location_name} ({$smallWarehouseId})\n";

    DB::beginTransaction();
    try {
        DB::statement('SET TRANSACTION READ ONLY');

        foreach ($sourceQuery()->lazy(500) as $source) {
            $summary['source_rows']++;
            $reconciliation = DB::table('inbound_backfill_reconciliations')
                ->where('source_movement_id', $source->id)
                ->first();
            if (!$reconciliation) {
                $summary['missing_reconciliation']++;
                continue;
            }

            $summary['reconciled_rows']++;
            if ((int) $reconciliation->qty !== abs((int) $source->qty)) {
                $summary['invalid_reconciliation_qty']++;
            }

            $correctionNumber = substr((string) $source->transaction_number, 0, 70)
                .'-RECLASS-'.substr((string) $source->id, -8);
            $defaultCorrectionCount = DB::table('inventory_movements')
                ->where('item_id', $source->item_id)
                ->where('location_id', $source->location_id)
                ->where('bin_id', $reconciliation->inbound_bin_id)
                ->where('transaction_number', $correctionNumber)
                ->where('source', 'BACKFILL_INBOUND_RESTORE')
                ->where('qty', -(int) $source->qty)
                ->where('created_by', 'system:inbound-backfill-reconcile')
                ->count();
            if ($defaultCorrectionCount !== 1) {
                $summary['missing_default_correction']++;
            }

            $targetMovementCount = DB::table('inventory_movements')
                ->where('item_id', $source->item_id)
                ->where('location_id', $source->location_id)
                ->where('bin_id', $reconciliation->target_bin_id)
                ->where('transaction_number', $source->transaction_number)
                ->where('source', $source->source)
                ->where('qty', (int) $source->qty)
                ->where('created_by', 'system:inbound-backfill-reconcile')
                ->count();
            if ($targetMovementCount !== 1) {
                $summary['missing_target_movement']++;
            }

            if ($source->source === 'ORDER_COMPLETE_OUT') {
                $orderId = DB::table('sales_orders')
                    ->where('salesorder_no', $source->transaction_number)
                    ->value('id');
                if ($orderId) {
                    $summary['allocation_still_default'] += DB::table('order_bin_allocations')
                        ->where('order_id', $orderId)
                        ->where('item_id', $source->item_id)
                        ->where('location_id', $source->location_id)
                        ->where('bin_id', $source->bin_id)
                        ->count();
                }
            }

            if ($summary['source_rows'] % 1000 === 0) {
                echo "PROGRESS source_rows={$summary['source_rows']}\n";
                gc_collect_cycles();
            }
        }

        $groups = DB::table('inventory_movements as im')
            ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
            ->where('im.source', 'ORDER_COMPLETE_OUT')
            ->where('im.created_by', 'system:backfill')
            ->where('im.qty', '<', 0)
            ->where('inbound_bin.is_inbound', true)
            ->where('im.location_id', $smallWarehouseId)
            ->select(['im.item_id', 'im.location_id', 'im.bin_id'])
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $defaultCurrent = (int) DB::table('inventories')
                ->where('item_id', $group->item_id)
                ->where('location_id', $group->location_id)
                ->where('bin_id', $group->bin_id)
                ->sum('on_hand');
            $canonicalPending = (int) DB::table('inbound_items as ii')
                ->join('inbounds as ib', 'ib.id', '=', 'ii.inbound_id')
                ->where('ii.item_id', $group->item_id)
                ->where('ib.location_id', $group->location_id)
                ->whereNotIn('ib.status', ['CANCELLED', 'CANCELED'])
                ->selectRaw('COALESCE(SUM(GREATEST(ii.received_qty-ii.putaway_qty,0)),0) AS pending')
                ->value('pending');
            if ($defaultCurrent !== $canonicalPending) {
                $summary['group_default_mismatch']++;
            }
        }

        $summary['available_drift_rows'] = DB::table('inventories as inventory')
            ->leftJoin('location_bins as bin', 'bin.id', '=', 'inventory.bin_id')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('inbound_backfill_reconciliations as reconciliation')
                    ->whereColumn('reconciliation.item_id', 'inventory.item_id')
                    ->whereColumn('reconciliation.location_id', 'inventory.location_id');
            })
            ->whereRaw('inventory.available <> CASE WHEN bin.is_inbound IS TRUE OR inventory.bin_id IS NULL THEN 0 ELSE inventory.on_hand-inventory.on_order END')
            ->count();

        echo "\nPOST-AUDIT SUMMARY\n";
        foreach ($summary as $key => $value) {
            echo "{$key}={$value}\n";
        }

        $safe = $summary['source_rows'] === $summary['reconciled_rows']
            && $summary['missing_reconciliation'] === 0
            && $summary['invalid_reconciliation_qty'] === 0
            && $summary['missing_default_correction'] === 0
            && $summary['missing_target_movement'] === 0
            && $summary['group_default_mismatch'] === 0
            && $summary['available_drift_rows'] === 0
            && $summary['allocation_still_default'] === 0;
        echo 'POST_AUDIT_RESULT='.($safe ? 'PASS' : 'FAIL')."\n";
    } finally {
        DB::rollBack();
    }

    echo "DATABASE TIDAK DIUBAH\n";
})();
PHP
```

Kriteria akhir:

```text
missing_reconciliation=0
invalid_reconciliation_qty=0
missing_default_correction=0
missing_target_movement=0
group_default_mismatch=0
available_drift_rows=0
allocation_still_default=0
POST_AUDIT_RESULT=PASS
```

## 5. Verifikasi idempotensi

Setelah post-audit `PASS`, jalankan kembali perintah dry-run langkah 1. Hasilnya harus:

```text
source_rows=0
blocked_rows=0
group_mismatch=0
net_location_delta=0
READY_TO_APPLY=NO
```

`READY_TO_APPLY=NO` pada tahap ini berarti tidak ada movement tersisa, bukan kegagalan.

Jangan menjalankan apply lagi ketika `remaining=0`.
