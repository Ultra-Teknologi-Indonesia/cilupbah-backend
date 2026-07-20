<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

class PicklistFailAndSplitTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Tester',
            'email' => 'tester+'.substr($id, 0, 6).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // User disisipkan lewat query builder, jadi belum punya role. Endpoint
        // outbound ter-gate permission, karena itu beri role owner (lolos via
        // Gate::before) supaya test menguji perilaku, bukan otorisasi.
        \App\Models\User::find($id)->assignRole(
            \App\Models\Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web'])
        );

        return $id;
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-'.substr($id, 0, 6),
            'location_name' => 'Gudang',
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
            'name' => 'Prod-'.$sku,
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

    private function seedOrder(string $locationId): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => 'SO-'.substr($id, 0, 6),
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedOrderItem(string $orderId, string $itemId, string $sku, int $qty): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $id,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedInventory(string $itemId, string $locationId, string $binId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPicklistWithItem(string $locationId, string $itemId, string $sku, int $ordered, int $picked = 0): array
    {
        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-'.substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = $this->seedOrder($locationId);
        $orderItemId = $this->seedOrderItem($orderId, $itemId, $sku, $ordered);

        $itemPkId = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $itemPkId,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => $ordered,
            'qty_picked' => $picked,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['picklist_id' => $picklistId, 'item_id' => $itemPkId, 'order_id' => $orderId];
    }

    public function test_fail_item_happy_path_marks_short_without_stock_mutation(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-A');
        $this->seedInventory($variantId, $locationId, $binId, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-A', 5);

        $service = app(PicklistService::class);
        $service->failPickItem($ids['picklist_id'], $ids['item_id'], PicklistItem::REASON_STOCK_EMPTY, null, $userId);

        $item = PicklistItem::find($ids['item_id']);
        $this->assertSame(PicklistItem::STATUS_SHORT, $item->item_status);
        $this->assertSame(0, (int) $item->qty_picked);
        $this->assertSame(5, (int) $item->failed_qty);

        $inv = Inventory::where('bin_id', $binId)->first();
        $this->assertSame(10, (int) $inv->on_hand);
    }

    public function test_fail_item_rejected_when_picklist_completed(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R2');
        $variantId = $this->seedProductVariant('SKU-B');
        $this->seedInventory($variantId, $locationId, $binId, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-B', 5);
        DB::table('picklists')->where('id', $ids['picklist_id'])->update(['status' => Picklist::STATUS_COMPLETED]);

        $this->expectException(\Modules\Outbound\Exceptions\OutboundValidationException::class);
        app(PicklistService::class)->failPickItem($ids['picklist_id'], $ids['item_id'], PicklistItem::REASON_STOCK_EMPTY, null, $userId);
    }

    public function test_unfail_resets_state(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R3');
        $variantId = $this->seedProductVariant('SKU-C');
        $this->seedInventory($variantId, $locationId, $binId, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-C', 4);
        $svc = app(PicklistService::class);
        $svc->failPickItem($ids['picklist_id'], $ids['item_id'], PicklistItem::REASON_STOCK_EMPTY, null, $userId);
        $svc->unfailPickItem($ids['picklist_id'], $ids['item_id'], $userId);

        $item = PicklistItem::find($ids['item_id']);
        $this->assertNull($item->item_status);
        $this->assertNull($item->fail_reason_code);
        $this->assertNull($item->failed_qty);
    }

    public function test_complete_picklist_allowed_when_items_are_short(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R4');
        $variantId = $this->seedProductVariant('SKU-D');
        $this->seedInventory($variantId, $locationId, $binId, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-D', 3);
        $svc = app(PicklistService::class);
        $svc->failPickItem($ids['picklist_id'], $ids['item_id'], PicklistItem::REASON_STOCK_EMPTY, null, $userId);

        $picklist = $svc->complete($ids['picklist_id']);
        $this->assertSame(Picklist::STATUS_COMPLETED, $picklist->status);
    }

    public function test_split_pick_creates_allocations_and_decrements_inventory(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binA = $this->seedBin($locationId, 'L1-B1-K1-R5');
        $binB = $this->seedBin($locationId, 'L1-B1-K1-R6');
        $variantId = $this->seedProductVariant('SKU-E');
        $this->seedInventory($variantId, $locationId, $binA, 5);
        $this->seedInventory($variantId, $locationId, $binB, 5);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-E', 8);

        app(PicklistService::class)->splitPickItem($ids['picklist_id'], $ids['item_id'], [
            ['bin_code' => 'L1-B1-K1-R5', 'qty' => 5],
            ['bin_code' => 'L1-B1-K1-R6', 'qty' => 3],
        ], $userId);

        $allocations = PicklistItemAllocation::where('picklist_item_id', $ids['item_id'])->get();
        $this->assertCount(2, $allocations);
        $item = PicklistItem::find($ids['item_id']);
        $this->assertSame(8, (int) $item->qty_picked);

        $this->assertSame(0, (int) Inventory::where('bin_id', $binA)->value('on_hand'));
        $this->assertSame(2, (int) Inventory::where('bin_id', $binB)->value('on_hand'));
    }

    public function test_split_pick_over_remaining_rolls_back(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binA = $this->seedBin($locationId, 'L1-B1-K1-R7');
        $binB = $this->seedBin($locationId, 'L1-B1-K1-R8');
        $variantId = $this->seedProductVariant('SKU-F');
        $this->seedInventory($variantId, $locationId, $binA, 10);
        $this->seedInventory($variantId, $locationId, $binB, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-F', 5);

        try {
            app(PicklistService::class)->splitPickItem($ids['picklist_id'], $ids['item_id'], [
                ['bin_code' => 'L1-B1-K1-R7', 'qty' => 4],
                ['bin_code' => 'L1-B1-K1-R8', 'qty' => 4],
            ], $userId);
            $this->fail('Expected OutboundValidationException');
        } catch (\Modules\Outbound\Exceptions\OutboundValidationException $e) {

        }

        $this->assertSame(0, PicklistItemAllocation::where('picklist_item_id', $ids['item_id'])->count());
        $this->assertSame(10, (int) Inventory::where('bin_id', $binA)->value('on_hand'));
        $this->assertSame(10, (int) Inventory::where('bin_id', $binB)->value('on_hand'));
    }

    public function test_reason_other_without_note_returns_validation_error(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R9');
        $variantId = $this->seedProductVariant('SKU-G');
        $this->seedInventory($variantId, $locationId, $binId, 10);
        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-G', 5);

        $this->actingAs(\App\Models\User::find($userId), 'sanctum');
        $response = $this->postJson(
            "/api/v1/outbound/picklists/{$ids['picklist_id']}/items/{$ids['item_id']}/fail",
            ['reason_code' => 'OTHER'],
        );
        $response->assertStatus(422);
    }
}
