<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseSerialNumber;

class PurchaseSerialNumberRepository
{
    public function getByBillItemId(string $billDetailId)
    {
        return PurchaseSerialNumber::where('purchase_bill_item_id', $billDetailId)
            ->orderBy('serial_number')
            ->get();
    }

    public function markPrintedByIds(array $ids, ?string $printedBy): int
    {
        return PurchaseSerialNumber::whereIn('id', $ids)
            ->whereNull('printed_at')
            ->update([
                'printed_at' => now(),
                'printed_by' => $printedBy,
            ]);
    }
}
