<?php

namespace Modules\Outbound\Tests\Feature;

use App\Exceptions\UserFacingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

class PickingConcurrencyTest extends TestCase
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

    private function seedPicklistWithItem(
        string $locationId,
        string $itemId,
        string $sku,
        int $ordered,
        string $userId,
        int $picked = 0,
    ): array {
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
            'qty_picked' => $picked,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['picklist_id' => $picklistId, 'item_id' => $itemPkId, 'order_id' => $orderId];
    }

    private function scenario(int $onHand = 10, int $ordered = 10): array
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-CONC');
        $this->seedInventory($variantId, $locationId, $binId, $onHand);

        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-CONC', $ordered, $userId);

        return $ids + ['user_id' => $userId, 'item_variant_id' => $variantId, 'bin_id' => $binId];
    }

    private function onHand(string $variantId): int
    {
        return (int) DB::table('inventories')->where('item_id', $variantId)->value('on_hand');
    }

    private function qtyPicked(string $itemId): int
    {
        return (int) DB::table('picklist_items')->where('id', $itemId)->value('qty_picked');
    }

    private function allocatedTotal(string $itemId): int
    {
        return (int) DB::table('picklist_item_allocations')->where('picklist_item_id', $itemId)->sum('qty');
    }

    public function test_concurrent_delta_picks_accumulate_and_do_not_lose_updates(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);
        $service = app(PicklistService::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_delta' => 3,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_delta' => 4,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame(7, $this->qtyPicked($s['item_id']), 'Kedua pick harus terakumulasi, bukan saling menimpa.');
        $this->assertSame(3, $this->onHand($s['item_variant_id']), 'Stok harus turun persis 7.');

        $this->assertSame(
            $this->allocatedTotal($s['item_id']),
            $this->qtyPicked($s['item_id']),
            'qty_picked dan total alokasi harus konsisten.',
        );
    }

    public function test_pick_beyond_qty_ordered_is_rejected_with_409_and_leaves_stock_untouched(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 5);
        $service = app(PicklistService::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_delta' => 5,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $stockAfterFull = $this->onHand($s['item_variant_id']);

        try {
            $service->pickItem($s['picklist_id'], $s['item_id'], [
                'qty_delta' => 1,
                'bin_code' => 'L1-B1-K1-R1',
            ]);
            $this->fail('Scan pada item yang sudah lengkap seharusnya ditolak.');
        } catch (UserFacingException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('ITEM_ALREADY_FULL', $e->getErrors()['code']);
            $this->assertSame(5, $e->getErrors()['qty_picked'], 'Payload harus membawa qty otoritatif untuk sinkronisasi klien.');
            $this->assertSame(5, $e->getErrors()['qty_ordered']);
        }

        $this->assertSame(5, $this->qtyPicked($s['item_id']));
        $this->assertSame($stockAfterFull, $this->onHand($s['item_variant_id']), 'Penolakan tidak boleh menyentuh stok.');
    }

    public function test_absolute_qty_picked_still_supported_for_manual_correction(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);
        $service = app(PicklistService::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_picked' => 6,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame(6, $this->qtyPicked($s['item_id']));
        $this->assertSame(4, $this->onHand($s['item_variant_id']));
    }

    public function test_negative_correction_returns_stock_and_lowers_qty_picked(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);
        $service = app(PicklistService::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_delta' => 5,
            'bin_code' => 'L1-B1-K1-R1',
        ]);
        $this->assertSame(5, $this->onHand($s['item_variant_id']));

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_picked' => 2,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $this->assertSame(2, $this->qtyPicked($s['item_id']));
        $this->assertSame(8, $this->onHand($s['item_variant_id']), 'Koreksi turun harus mengembalikan 3 unit ke rak.');
    }

    /**
     * Atribusi dibaca dari picklist_item_allocations lewat subselect di
     * getItemsPaginated. Kalau subselect-nya salah, seluruh endpoint daftar item
     * ikut rusak -- jadi dikunci di sini.
     */
    public function test_items_listing_exposes_who_picked_last(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);
        $service = app(PicklistService::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_delta' => 2,
            'bin_code' => 'L1-B1-K1-R1',
        ]);

        $items = app(\Modules\Outbound\Repositories\PicklistRepository::class)
            ->getItemsPaginated($s['picklist_id'], 10);

        $row = collect($items->items())->firstWhere('id', $s['item_id']);

        $this->assertNotNull($row);
        $this->assertSame('Picker', $row->last_picked_by_name);
        $this->assertNotNull($row->last_picked_at);
    }

    public function test_items_listing_has_null_attribution_before_any_pick(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);

        $items = app(\Modules\Outbound\Repositories\PicklistRepository::class)
            ->getItemsPaginated($s['picklist_id'], 10);

        $row = collect($items->items())->firstWhere('id', $s['item_id']);

        $this->assertNotNull($row);
        $this->assertNull($row->last_picked_by_name);
    }

    public function test_negative_target_is_rejected(): void
    {
        $s = $this->scenario(onHand: 10, ordered: 10);
        $service = app(PicklistService::class);

        $this->expectException(\Modules\Outbound\Exceptions\OutboundValidationException::class);

        $service->pickItem($s['picklist_id'], $s['item_id'], [
            'qty_picked' => -1,
            'bin_code' => 'L1-B1-K1-R1',
        ]);
    }
}
