<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

class OrderReleasePerOrderTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Picker',
            'email' => 'picker+'.substr($id, 0, 6).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

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

    private function seedOrderWithPicklistItem(
        string $picklistId,
        string $locationId,
        string $variantId,
        string $sku,
        int $ordered,
    ): array {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-'.substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $variantId,
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
            'item_id' => $variantId,
            'sku' => $sku,
            'qty_ordered' => $ordered,
            'qty_picked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['order_id' => $orderId, 'item_id' => $itemPkId, 'order_item_id' => $orderItemId];
    }

    private function orderStatus(string $orderId): string
    {
        return (string) DB::table('sales_orders')->where('id', $orderId)->value('status');
    }

    private function picklistStatus(string $picklistId): string
    {
        return (string) DB::table('picklists')->where('id', $picklistId)->value('status');
    }

    public function test_completed_order_releases_while_sibling_order_still_in_progress(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-REL');
        $this->seedInventory($variantId, $locationId, $binId, 50);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-'.substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'picker_id' => $userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderA = $this->seedOrderWithPicklistItem($picklistId, $locationId, $variantId, 'SKU-REL', 3);
        $orderB = $this->seedOrderWithPicklistItem($picklistId, $locationId, $variantId, 'SKU-REL', 4);

        $service = app(PicklistService::class);

        $service->pickItem($picklistId, $orderA['item_id'], [
            'qty_delta' => 3,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame(
            'picked',
            $this->orderStatus($orderA['order_id']),
            'Pesanan yang itemnya sudah lengkap harus langsung lepas ke tahap berikutnya.',
        );

        $this->assertSame(
            'reserved',
            $this->orderStatus($orderB['order_id']),
            'Pesanan lain di picklist yang sama tidak boleh ikut terlepas.',
        );

        $this->assertSame(
            Picklist::STATUS_IN_PROGRESS,
            $this->picklistStatus($picklistId),
            'Picklist tetap berjalan; rilis pesanan tidak menunggu picklist selesai.',
        );

        $this->assertSame(
            'PICKED',
            (string) DB::table('sales_order_items')->where('id', $orderA['order_item_id'])->value('fulfillment_status'),
        );
    }

    public function test_partially_picked_order_is_not_released(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-PART');
        $this->seedInventory($variantId, $locationId, $binId, 50);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-'.substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'picker_id' => $userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->seedOrderWithPicklistItem($picklistId, $locationId, $variantId, 'SKU-PART', 5);

        app(PicklistService::class)->pickItem($picklistId, $order['item_id'], [
            'qty_delta' => 2,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame(
            'reserved',
            $this->orderStatus($order['order_id']),
            'Pesanan yang baru sebagian tidak boleh lepas.',
        );
    }

    public function test_release_is_idempotent_across_repeated_picks(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-IDEM');
        $this->seedInventory($variantId, $locationId, $binId, 50);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-'.substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'picker_id' => $userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->seedOrderWithPicklistItem($picklistId, $locationId, $variantId, 'SKU-IDEM', 2);
        $service = app(PicklistService::class);

        $service->pickItem($picklistId, $order['item_id'], [
            'qty_delta' => 2,
            'bin_code' => 'L1-B1-K1-R1',
        ]);
        $this->assertSame('picked', $this->orderStatus($order['order_id']));

        $service->pickItem($picklistId, $order['item_id'], [
            'qty_picked' => 1,
            'bin_code' => 'L1-B1-K1-R1',
        ]);
        $service->pickItem($picklistId, $order['item_id'], [
            'qty_delta' => 1,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame('picked', $this->orderStatus($order['order_id']));
    }
}
