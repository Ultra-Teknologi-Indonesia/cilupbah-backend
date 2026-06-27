<?php

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;

$service = app(PurchaseOrderService::class);

$draftPos = PurchaseOrder::where('status', 'DRAFT')->get();

echo "Ditemukan " . $draftPos->count() . " PO dengan status DRAFT.\n";

$updated = 0;
foreach ($draftPos as $po) {
    try {
        $service->approve($po->id);
        echo "PO " . $po->po_number . " berhasil diubah ke OPEN.\n";
        $updated++;
    } catch (\Exception $e) {
        echo "Gagal mengubah PO " . $po->po_number . ": " . $e->getMessage() . "\n";
    }
}

echo "Berhasil mengubah " . $updated . " PO.\n";
