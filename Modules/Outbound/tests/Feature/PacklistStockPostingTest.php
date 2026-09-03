<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Services\PacklistService;
use Tests\TestCase;

class PacklistStockPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_packlist_posts_physical_stock_once_and_revert_reopens_it(): void
    {
        Queue::fake();

        [$userId, $locationId, $binId, $itemId, $orderId, $orderItemId, $packlistId] = $this->seedScenario();
        $this->actingAs(User::findOrFail($userId));

        $packlist = app(PacklistService::class)->complete($packlistId);

        $this->assertSame(Packlist::STATUS_COMPLETED, $packlist->status);
        $this->assertSame(3, (int) DB::table('inventories')->where('bin_id', $binId)->value('on_hand'));
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $packlist->packlist_no,
            'source' => 'PACKING',
            'qty' => -2,
        ]);
        $this->assertSame(2, (int) DB::table('picklist_item_allocations')->value('physical_committed_qty'));

        $this->expectException(OutboundValidationException::class);
        app(PacklistService::class)->complete($packlistId);

        $this->assertSame(3, (int) DB::table('inventories')->where('bin_id', $binId)->value('on_hand'));
    }

    public function test_reverting_completed_packlist_restores_stock_and_commitment(): void
    {
        Queue::fake();

        [$userId, $locationId, $binId, $itemId, $orderId, $orderItemId, $packlistId] = $this->seedScenario();
        $this->actingAs(User::findOrFail($userId));
        app(PacklistService::class)->complete($packlistId);
        DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'packed']);

        app(PacklistService::class)->revert($packlistId);

        $this->assertSame(5, (int) DB::table('inventories')->where('bin_id', $binId)->value('on_hand'));
        $this->assertSame(0, (int) DB::table('picklist_item_allocations')->value('physical_committed_qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'PACKING_REVERSAL',
            'qty' => 2,
        ]);
        $this->assertDatabaseMissing('packlists', ['id' => $packlistId]);
    }

    public function test_packing_is_atomic_when_physical_stock_is_no_longer_sufficient(): void
    {
        Queue::fake();

        [$userId, $locationId, $binId, $itemId, $orderId, $orderItemId, $packlistId] = $this->seedScenario(onHand: 1);
        $this->actingAs(User::findOrFail($userId));

        try {
            app(PacklistService::class)->complete($packlistId);
            $this->fail('Packing harus ditolak saat stok fisik tidak cukup.');
        } catch (OutboundValidationException $exception) {
            $this->assertStringContainsString('tidak cukup', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, (int) DB::table('inventories')->where('bin_id', $binId)->value('on_hand'));
        $this->assertDatabaseHas('packlists', [
            'id' => $packlistId,
            'status' => 'IN_PROGRESS',
        ]);
        $this->assertDatabaseMissing('inventory_movements', ['source' => 'PACKING']);
    }

    private function seedScenario(int $onHand = 5): array
    {
        $userId = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Packer',
            'email' => 'packer+'.substr($userId, 0, 8).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        User::findOrFail($userId)->assignRole(
            Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web'])
        );

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'PACK-'.substr($locationId, 0, 6),
            'location_name' => 'Packing Warehouse',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $locationId,
            'bin_code' => 'A-01',
            'bin_final_code' => 'PACK-A-01',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Packing Category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Packing Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $itemId,
            'product_id' => $productId,
            'sku' => 'PACK-SKU-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-PACK-1',
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => 'picked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => 'PACK-SKU-1',
            'qty_in_base' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-PACK-1',
            'location_id' => $locationId,
            'status' => 'COMPLETED',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pickItemId = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $pickItemId,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => 'PACK-SKU-1',
            'bin_id' => $binId,
            'qty_ordered' => 2,
            'qty_picked' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('picklist_item_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_item_id' => $pickItemId,
            'bin_id' => $binId,
            'qty' => 2,
            'physical_committed_qty' => 0,
            'picked_at' => now(),
            'picked_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packlistId = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $packlistId,
            'packlist_no' => 'PACK-PACK-1',
            'location_id' => $locationId,
            'order_id' => $orderId,
            'picklist_id' => $picklistId,
            'status' => 'IN_PROGRESS',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('packlist_items')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_id' => $packlistId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => 'PACK-SKU-1',
            'qty_ordered' => 2,
            'qty_packed' => 2,
            'barcode_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$userId, $locationId, $binId, $itemId, $orderId, $orderItemId, $packlistId];
    }
}
