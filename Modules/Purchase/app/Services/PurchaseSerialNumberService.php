<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Models\PurchaseSerialNumber;
use Illuminate\Support\Facades\DB;

class PurchaseSerialNumberService
{
    public function getByBillItemId(string $billDetailId)
    {
        return PurchaseSerialNumber::where('purchase_bill_item_id', $billDetailId)
            ->orderBy('serial_number')
            ->get();
    }

    public function markPrinted(array $data): int
    {
        return DB::transaction(function () use ($data) {
            return PurchaseSerialNumber::whereIn('id', $data['ids'])
                ->whereNull('printed_at')
                ->update([
                    'printed_at' => now(),
                    'printed_by' => $data['printed_by'] ?? null,
                ]);
        });
    }
}
