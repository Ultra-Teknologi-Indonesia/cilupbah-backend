<?php

namespace Modules\Sales\Services\Support;

use Illuminate\Support\Facades\DB;

class SalesOrderNumberGenerator
{
    /**
     * Generate the next manual sales order number.
     *
     * Format: SO-INT-YYMM-NNNNN (reset per bulan).
     * Row-lock counter untuk aman terhadap concurrency.
     */
    public function nextManualSalesOrderNo(): string
    {
        return $this->next('SO-INT');
    }

    public function next(string $prefix): string
    {
        $periodKey = now()->format('ym');

        return DB::transaction(function () use ($prefix, $periodKey) {
            $row = DB::table('sales_order_number_counters')
                ->where('prefix', $prefix)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                DB::table('sales_order_number_counters')->insert([
                    'prefix'     => $prefix,
                    'period_key' => $periodKey,
                    'last_seq'   => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $seq = 1;
            } else {
                $seq = ((int) $row->last_seq) + 1;
                DB::table('sales_order_number_counters')
                    ->where('id', $row->id)
                    ->update([
                        'last_seq'   => $seq,
                        'updated_at' => now(),
                    ]);
            }

            return sprintf('%s-%s-%05d', $prefix, $periodKey, $seq);
        });
    }
}
