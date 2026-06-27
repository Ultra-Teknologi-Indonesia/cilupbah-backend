<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            $service = app(PurchaseOrderService::class);
            $draftPos = PurchaseOrder::where('status', 'DRAFT')->get();

            foreach ($draftPos as $po) {
                try {
                    $service->approve($po->id);
                } catch (\Exception $e) {
                    // Log error if needed, but continue
                    \Log::error("Gagal migrasi PO DRAFT ke OPEN: {$po->po_number}. Error: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            \Log::error("Gagal load PurchaseOrderService untuk migrasi: " . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down needed as we don't know which were originally DRAFT
    }
};
