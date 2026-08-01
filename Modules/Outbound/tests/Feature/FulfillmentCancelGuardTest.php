<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Services\PacklistService;
use Tests\TestCase;

class FulfillmentCancelGuardTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $status, bool $canceled): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => 'SO-FG-' . substr($id, 0, 6),
            'customer_name' => 'Buyer',
            'source' => 'shopee',
            'status' => $status,
            'is_canceled' => $canceled,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_packing_scan_rejects_cancelled_order(): void
    {
        $orderId = $this->seedOrder('cancelled', true);
        $no = DB::table('sales_orders')->where('id', $orderId)->value('salesorder_no');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/DIBATALKAN/i');

        app(PacklistService::class)->scanOrder($no);
    }

    public function test_packing_scan_returns_null_for_unknown_order(): void
    {

        $this->assertNull(app(PacklistService::class)->scanOrder('SO-DOES-NOT-EXIST'));
    }
}
