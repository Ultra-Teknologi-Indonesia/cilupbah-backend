<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exports\CancelledOrdersExport;
use Tests\TestCase;

class ExportCancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function seedCancelled(bool $postPack, ?string $requestedAt = null): string
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-CAN-' . substr($orderId, 0, 4),
            'customer_name' => 'A',
            'source' => 'shopee',
            'status' => 'cancelled',
            'is_canceled' => true,
            'cancel_requested_at' => $requestedAt ?? now(),
            'cancel_accepted_at' => now(),
            'handed_to_warehouse_at' => $postPack ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sales_order_items')->insert([
            'id' => Str::uuid()->toString(),
            'order_id' => $orderId,
            'sku' => 'S',
            'qty_in_base' => 1,
            'price' => 100,
            'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    public function test_export_returns_only_post_pack_when_flag_set(): void
    {
        Excel::fake();

        $postPackId = $this->seedCancelled(true);
        $this->seedCancelled(false);

        $export = new CancelledOrdersExport(null, null, true, null);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($postPackId, $rows->first()->id);
    }

    public function test_export_all_when_flag_false(): void
    {
        $this->seedCancelled(true);
        $this->seedCancelled(false);

        $export = new CancelledOrdersExport(null, null, false, null);
        $rows = $export->collection();

        $this->assertCount(2, $rows);
    }

    public function test_export_source_filter(): void
    {
        $shopeeId = $this->seedCancelled(true);
        DB::table('sales_orders')->where('id', $shopeeId)->update(['source' => 'shopee']);

        $otherId = $this->seedCancelled(true);
        DB::table('sales_orders')->where('id', $otherId)->update(['source' => 'tiktok']);

        $export = new CancelledOrdersExport(null, null, false, 'shopee');
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('shopee', $rows->first()->source);
    }
}
