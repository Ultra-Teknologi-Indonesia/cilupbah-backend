<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

class PickingNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Tester',
            'email' => 'tester+' . substr($id, 0, 6) . '@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-' . substr($id, 0, 6),
            'location_name' => 'Gudang Pick Neg',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedBin(string $locationId, string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'is_inbound' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Prod-' . $sku,
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $variantId;
    }

    private function seedInventory(string $itemId, string $locationId, string $binId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'reserved' => 0,
            'available' => $onHand,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPicklistWithItem(string $locationId, string $itemId, string $sku, int $ordered, string $userId): array
    {
        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-' . substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-' . substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => $ordered,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemPkId = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $itemPkId,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => $ordered,
            'qty_picked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['picklist_id' => $picklistId, 'item_id' => $itemPkId];
    }

    public function test_pick_qty_exceeding_bin_stock_always_throws_validation_exception(): void
    {
        Queue::fake();

        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'RACK-PICK-NEG');
        $variantId = $this->seedProductVariant('SKU-PN-1');
        $this->seedInventory($variantId, $locationId, $binId, 1);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-PN-1', 5, $userId);

        $this->actingAs(\App\Models\User::find($userId), 'sanctum');

        $this->expectException(OutboundValidationException::class);
        $this->expectExceptionMessage('Stok tidak cukup di rak');

        app(PicklistService::class)->pickItem($ids['picklist_id'], $ids['item_id'], [
            'qty_picked' => 5,
            'bin_code' => 'RACK-PICK-NEG',
        ]);
    }
}
