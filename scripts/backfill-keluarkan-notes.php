<?php

/**
 * ============================================================================
 * BACKFILL — Dokumen Koreksi Stok untuk mutasi ADJUSTMENT lama (tanpa dokumen)
 * ============================================================================
 *
 * MASALAH
 * -------
 * Aksi "Keluarkan SKU dari rak" versi awal (dan penyesuaian manual lama lewat
 * tinker) menulis mutasi stok telanjang di `inventory_movements`:
 *   - source = 'ADJUSTMENT'
 *   - transaction_number = 'ADJ-XXXXXXXX' (8 huruf ACAK, mis. ADJ-BVIKCUDZ)
 *   - TIDAK terhubung ke dokumen `stock_adjustments`
 * Akibatnya mutasi itu MUNCUL di Kronologi Stok, tapi:
 *   - TIDAK muncul di halaman Transaksi Stok › Koreksi Stok
 *   - kolom KETERANGAN (ref_note) kosong "—"
 *
 * APA YANG DILAKUKAN SKRIP INI
 * ----------------------------
 * Membuat dokumen `stock_adjustments` (+ `stock_adjustment_items`) untuk tiap
 * mutasi ADJUSTMENT yatim tersebut, memakai `adjustment_no = transaction_number`
 * yang SAMA (mis. ADJ-BVIKCUDZ) agar:
 *   - dokumen muncul di Transaksi Stok › Koreksi Stok
 *   - KETERANGAN di Kronologi terisi otomatis (ref_note mencocokkan adjustment_no)
 * system_qty / actual_qty / difference_qty direkonstruksi dari kolom mutasi:
 *   difference = qty ; actual = balance (sesudah) ; system = balance - qty (sebelum)
 *
 * PENTING (AMAN)
 * --------------
 * - TIDAK menyentuh stok sama sekali. Hanya INSERT baris dokumen. Job
 *   ProcessStockAdjustmentJob TIDAK dipanggil, jadi on_hand tidak berubah.
 * - IDEMPOTEN. Nomor yang sudah punya dokumen dilewati; aman dijalankan ulang.
 * - Hanya menyasar nomor ACAK (ADJ-XXXX non-numerik). Dokumen sekuensial
 *   (ADJ-000000123) diabaikan karena sudah punya dokumen sendiri.
 *
 * CARA MENJALANKAN (container staging: cilupbah-staging)
 * ------------------------------------------------------
 *   # 1) DRY-RUN (default) — hanya menghitung, tidak menulis apa pun:
 *   docker exec -i cilupbah-staging php artisan tinker < scripts/backfill-keluarkan-notes.php
 *
 *   # 2) EKSEKUSI SUNGGUHAN — set env BACKFILL_APPLY=1:
 *   docker exec -e BACKFILL_APPLY=1 -i cilupbah-staging php artisan tinker < scripts/backfill-keluarkan-notes.php
 * ============================================================================
 */

$apply = getenv('BACKFILL_APPLY') === '1';

echo $apply
    ? "MODE: APPLY (menulis dokumen)\n"
    : "MODE: DRY-RUN (tidak menulis apa pun; set BACKFILL_APPLY=1 untuk eksekusi)\n";

// Nomor dokumen yang SUDAH ada (termasuk yang soft-deleted) → jangan dobel.
$existing = array_flip(
    \Modules\Inventory\Models\StockAdjustment::withTrashed()
        ->pluck('adjustment_no')
        ->all()
);

// Mutasi ADJUSTMENT yatim: nomor ACAK (bukan '^ADJ-[0-9]+$') dan belum ada dokumennya.
$groups = \Modules\Inventory\Models\InventoryMovement::query()
    ->where('source', 'ADJUSTMENT')
    ->where('transaction_number', 'like', 'ADJ-%')
    ->whereRaw("transaction_number !~ '^ADJ-[0-9]+$'")
    ->orderBy('transaction_date')
    ->get()
    ->groupBy('transaction_number');

$docsCreated = 0;
$itemsCreated = 0;
$skippedExisting = 0;

foreach ($groups as $txn => $movements) {
    if (isset($existing[$txn])) {
        $skippedExisting++;
        continue;
    }

    $first = $movements->first();

    // Keterangan otomatis (berbasis rak bila ada).
    $binCode = null;
    if ($first->bin_id) {
        $binCode = optional(
            \Modules\Warehouse\Models\LocationBin::find($first->bin_id)
        )->bin_final_code;
    }
    $note = $binCode
        ? "Keluarkan / penyesuaian stok rak {$binCode} (backfill dari Kronologi)"
        : 'Penyesuaian stok (backfill dari Kronologi)';

    // "user:UUID" → resolve ke NAMA user (kolom Dibuat Oleh menampilkan apa adanya,
    // dan flow normal menyimpan nama, bukan UUID). Fallback ke nilai asli bila
    // user tak ditemukan (mis. "system").
    $rawBy = preg_replace('/^user:/', '', (string) $first->created_by);
    $createdBy = optional(\App\Models\User::find($rawBy))->name ?? $rawBy;

    echo sprintf(
        "%s  %s  %d item  → %s\n",
        $apply ? '[BUAT]' : '[DRY ]',
        $txn,
        $movements->count(),
        $note
    );

    if (! $apply) {
        $docsCreated++;
        $itemsCreated += $movements->count();
        continue;
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($txn, $movements, $first, $note, $createdBy, &$itemsCreated) {
        $doc = \Modules\Inventory\Models\StockAdjustment::create([
            'adjustment_no' => $txn,                    // samakan dgn nomor mutasi → ref_note match
            'transaction_date' => $first->transaction_date,
            'location_id' => $first->location_id,
            'is_beginning_balance' => false,
            'notes' => $note,
            'created_by' => $createdBy,
        ]);

        foreach ($movements as $m) {
            $after = (int) $m->balance;          // saldo sesudah mutasi
            $diff = (int) $m->qty;               // selisih = qty mutasi
            $before = $after - $diff;            // saldo sebelum

            \Modules\Inventory\Models\StockAdjustmentItem::create([
                'stock_adjustment_id' => $doc->id,
                'item_id' => $m->item_id,
                'bin_id' => $m->bin_id,
                'system_qty' => $before,
                'actual_qty' => $after,
                'difference_qty' => $diff,
                'unit_cost' => null,
                'notes' => $note,
            ]);
            $itemsCreated++;
        }
    });

    $docsCreated++;
}

echo "----------------------------------------------------------------\n";
echo sprintf(
    "%s — dokumen: %d, item: %d, dilewati (sudah ada dokumen): %d\n",
    $apply ? 'SELESAI' : 'DRY-RUN SELESAI',
    $docsCreated,
    $itemsCreated,
    $skippedExisting
);
