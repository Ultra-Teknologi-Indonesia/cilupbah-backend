<?php

$apply = getenv('BACKFILL_APPLY') === '1';

echo $apply
    ? "MODE: APPLY (menulis dokumen)\n"
    : "MODE: DRY-RUN (tidak menulis apa pun; set BACKFILL_APPLY=1 untuk eksekusi)\n";

$existing = array_flip(
    \Modules\Inventory\Models\StockAdjustment::withTrashed()
        ->pluck('adjustment_no')
        ->all()
);

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

    $binCode = null;
    if ($first->bin_id) {
        $binCode = optional(
            \Modules\Warehouse\Models\LocationBin::find($first->bin_id)
        )->bin_final_code;
    }
    $note = $binCode
        ? "Keluarkan / penyesuaian stok rak {$binCode} (backfill dari Kronologi)"
        : 'Penyesuaian stok (backfill dari Kronologi)';

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
            'adjustment_no' => $txn,                    
            'transaction_date' => $first->transaction_date,
            'location_id' => $first->location_id,
            'is_beginning_balance' => false,
            'notes' => $note,
            'created_by' => $createdBy,
        ]);

        foreach ($movements as $m) {
            $after = (int) $m->balance;          
            $diff = (int) $m->qty;               
            $before = $after - $diff;            

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
