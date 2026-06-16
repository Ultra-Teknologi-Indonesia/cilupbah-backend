<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Services\InboundService;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Services\SalesReturnSettingService;
use Tests\TestCase;

/**
 * F3.4 — when auto-accept is enabled but accepting the return fails, the return
 * stays PENDING and an AdminAlertJob is dispatched so it is not silently lost.
 */
class SalesReturnAutoAcceptAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_auto_accept_alerts_admin_and_keeps_return_pending(): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat Retur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Retur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => 'SKU-RET',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-RET',
            'location_name' => 'Gudang Retur',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Auto-accept ON, but the inbound (restock) step blows up.
        $this->mock(SalesReturnSettingService::class, function ($m) {
            $m->shouldReceive('autoAccept')->andReturn(true);
            $m->shouldReceive('restockLocationId')->andReturn(null);
        });
        $this->mock(InboundService::class, function ($m) {
            $m->shouldReceive('receiveFromSalesReturn')->andThrow(new \RuntimeException('inbound down'));
        });

        Bus::fake([AdminAlertJob::class]);

        $service = app(SalesReturnService::class);

        $return = $service->create([
            'location_id' => $locationId,
            'created_by'  => 'tester',
            'items'       => [['item_id' => $variantId, 'qty' => 1]],
        ]);

        Bus::assertDispatched(AdminAlertJob::class);

        $this->assertDatabaseHas('sales_returns', [
            'id'     => $return->id,
            'status' => SalesReturn::STATUS_PENDING,
        ]);
    }
}
