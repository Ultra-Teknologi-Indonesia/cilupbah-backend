<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use App\Exceptions\UserFacingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\StockService;
use Tests\TestCase;

class ChannelOrderWarehouseRuleTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderService $service;

    private string $smallLocationId;

    private string $centralLocationId;

    private string $smallBinId;

    private string $centralBinId;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->service = app(SalesOrderService::class);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Lokasi Order',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Lokasi Order',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-CHANNEL-LOCATION',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->smallLocationId = DB::table('locations')
            ->where('location_code', 'O')
            ->value('id');
        if (! $this->smallLocationId) {
            $this->smallLocationId = $this->createLocation('O', 'Gudang Kecil', true);
        } else {
            DB::table('locations')->where('id', $this->smallLocationId)->update([
                'is_warehouse' => true,
                'is_small_warehouse' => true,
                'is_active' => true,
            ]);
        }

        $centralLocationId = DB::table('locations')
            ->where('location_code', 'WH-PUSAT')
            ->value('id');
        if (! $centralLocationId) {
            $centralLocationId = $this->createLocation('WH-PUSAT', 'Gudang Pusat', false);
        }
        $this->centralLocationId = (string) $centralLocationId;

        $this->smallBinId = $this->createBin($this->smallLocationId, 'O-A1-K1-X1');
        $this->centralBinId = $this->createBin($this->centralLocationId, 'P-A1-K1-X1');

        $this->createInventory($this->smallLocationId, $this->smallBinId, 20);
        $this->createInventory($this->centralLocationId, $this->centralBinId, 20);
    }

    public function test_channel_order_with_central_location_is_forced_to_small_warehouse(): void
    {
        $order = $this->service->createOrder([
            'salesorder_no' => 'LZ-LOCATION-NEW',
            'customer_name' => 'Buyer',
            'source' => 'lazada',
            'channel_shop_id' => 'SHOP-LOCATION',
            'location_id' => $this->centralLocationId,
            'items' => [[
                'sku' => 'SKU-CHANNEL-LOCATION',
                'description' => 'Produk',
                'qty_in_base' => 1,
                'price' => 1000,
            ]],
        ]);

        $this->assertSame($this->smallLocationId, $order->location_id);
        $this->assertSame(0, (int) $this->inventory($this->centralLocationId)->on_order);
        $this->assertSame(1, (int) $this->inventory($this->smallLocationId)->on_order);
        $this->assertSame($this->smallBinId, $this->inventory($this->smallLocationId)->bin_id);
    }

    public function test_relocating_pending_channel_order_moves_reservation_and_rack(): void
    {
        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'LZ-LOCATION-EXISTING',
            'source' => 'lazada',
            'is_manual' => false,
            'location_id' => $this->centralLocationId,
            'status' => 'pending',
        ]);

        $item = SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->variantId,
            'sku' => 'SKU-CHANNEL-LOCATION',
            'description' => 'Produk',
            'qty_in_base' => 1,
            'price' => 1000,
            'amount' => 1000,
        ]);

        app(StockService::class)->reserve(
            'SKU-CHANNEL-LOCATION',
            $this->variantId,
            $this->centralLocationId,
            1,
            $order->salesorder_no,
            false,
        );

        $relocated = $this->service->relocateOrder($order->fresh('items'), $this->smallLocationId, false);

        $this->assertSame($this->smallLocationId, $relocated->location_id);
        $this->assertSame(0, (int) $this->inventory($this->centralLocationId)->on_order);
        $this->assertSame(1, (int) $this->inventory($this->smallLocationId)->on_order);
        $this->assertSame($this->smallBinId, $this->inventory($this->smallLocationId)->bin_id);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $order->salesorder_no,
            'location_id' => $this->centralLocationId,
            'source' => 'ORDER_RELEASE',
            'qty' => -1,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $order->salesorder_no,
            'location_id' => $this->smallLocationId,
            'source' => 'ORDER_RESERVE',
            'qty' => 1,
        ]);
    }

    public function test_channel_sync_repairs_existing_order_still_pointing_to_central(): void
    {
        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'LZ-LOCATION-SYNC',
            'source' => 'lazada',
            'is_manual' => false,
            'location_id' => $this->centralLocationId,
            'status' => 'pending',
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->variantId,
            'sku' => 'SKU-CHANNEL-LOCATION',
            'description' => 'Produk',
            'qty_in_base' => 1,
            'price' => 1000,
            'amount' => 1000,
        ]);

        app(StockService::class)->reserve(
            'SKU-CHANNEL-LOCATION',
            $this->variantId,
            $this->centralLocationId,
            1,
            $order->salesorder_no,
            false,
        );

        $orderId = $this->service->upsertFromChannel([
            'salesorder_no' => $order->salesorder_no,
            'channel_shop_id' => 'SHOP-LOCATION',
            'source' => 'lazada',
            'channel_status' => 'PENDING',
            'customer_name' => 'Buyer',
            'transaction_date' => now(),
            'sub_total' => 1000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 1000,
            'is_paid' => true,
            'is_cod' => false,
            'payment_method' => null,
            'items' => [[
                'sku' => 'SKU-CHANNEL-LOCATION',
                'description' => 'Produk',
                'qty_in_base' => 1,
                'price' => 1000,
                'amount' => 1000,
            ]],
        ]);

        $this->assertSame($order->id, $orderId);
        $this->assertSame($this->smallLocationId, (string) SalesOrder::find($order->id)->location_id);
        $this->assertSame(0, (int) $this->inventory($this->centralLocationId)->on_order);
        $this->assertSame(1, (int) $this->inventory($this->smallLocationId)->on_order);
        $this->assertSame($this->smallBinId, $this->inventory($this->smallLocationId)->bin_id);
    }

    public function test_channel_order_cannot_be_relocated_to_central_warehouse(): void
    {
        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'LZ-LOCATION-GUARD',
            'source' => 'lazada',
            'is_manual' => false,
            'location_id' => $this->smallLocationId,
            'status' => 'pending',
        ]);

        $this->expectException(UserFacingException::class);
        $this->service->relocateOrder($order, $this->centralLocationId);
    }

    private function createLocation(string $code, string $name, bool $isSmall): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => $name,
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_small_warehouse' => $isSmall,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createBin(string $locationId, string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createInventory(string $locationId, string $binId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inventory(string $locationId): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $locationId)
            ->whereNotNull('bin_id')
            ->first();
    }
}
