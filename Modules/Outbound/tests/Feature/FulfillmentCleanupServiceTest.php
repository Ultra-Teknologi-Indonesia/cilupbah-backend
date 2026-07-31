<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\FulfillmentCleanupService;
use Tests\TestCase;

class FulfillmentCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locationId = $this->seedLocation();
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-' . substr($id, 0, 6),
            'location_name' => 'Gudang',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
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

    private function seedOrder(string $status, bool $canceled): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => 'SO-CL-' . substr($id, 0, 6),
            'customer_name' => 'Buyer',
            'source' => 'shopee',
            'location_id' => $this->locationId,
            'status' => $status,
            'is_canceled' => $canceled,
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

    private function seedPicklist(): string
    {
        $id = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $id,
            'picklist_no' => 'PICK-' . substr($id, 0, 6),
            'location_id' => $this->locationId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedPicklistItemForOrder(string $picklistId, string $orderId, string $sku, int $qtyPicked = 2): void
    {
        $itemId = $this->seedProductVariant($sku);
        $orderItemId = $this->seedOrderItem($orderId, $itemId, $sku, 2);
        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => 2,
            'qty_picked' => $qtyPicked,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPacklist(string $orderId, string $status): string
    {
        $id = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $id,
            'packlist_no' => 'PACK-' . substr($id, 0, 6),
            'location_id' => $this->locationId,
            'order_id' => $orderId,
            'status' => $status,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_removes_picklist_rows_and_drops_empty_picklist(): void
    {
        $orderId = $this->seedOrder('cancelled', true);
        $picklistId = $this->seedPicklist();
        $this->seedPicklistItemForOrder($picklistId, $orderId, 'SKU-A');

        app(FulfillmentCleanupService::class)->detachCancelledOrder($orderId);

        $this->assertSame(0, DB::table('picklist_items')->where('order_id', $orderId)->count());
        // Picklist tak dipakai order lain → ikut terhapus.
        $this->assertSame(0, DB::table('picklists')->where('id', $picklistId)->count());
        $this->assertDatabaseHas('fulfillment_removals', [
            'order_id' => $orderId,
            'stage' => 'picking',
            'reversed_stock' => false,
        ]);
    }

    public function test_keeps_shared_picklist_used_by_other_order(): void
    {
        $cancelled = $this->seedOrder('cancelled', true);
        $other = $this->seedOrder('reserved', false);
        $picklistId = $this->seedPicklist();
        $this->seedPicklistItemForOrder($picklistId, $cancelled, 'SKU-B');
        $this->seedPicklistItemForOrder($picklistId, $other, 'SKU-C');

        app(FulfillmentCleanupService::class)->detachCancelledOrder($cancelled);

        $this->assertSame(0, DB::table('picklist_items')->where('order_id', $cancelled)->count());
        // Baris order lain tetap; picklist induk tetap hidup.
        $this->assertSame(1, DB::table('picklist_items')->where('order_id', $other)->count());
        $this->assertSame(1, DB::table('picklists')->where('id', $picklistId)->count());
    }

    public function test_cancels_active_packlist(): void
    {
        $orderId = $this->seedOrder('cancelled', true);
        $packlistId = $this->seedPacklist($orderId, Packlist::STATUS_IN_PROGRESS);

        app(FulfillmentCleanupService::class)->detachCancelledOrder($orderId);

        $this->assertSame(
            Packlist::STATUS_CANCELLED,
            DB::table('packlists')->where('id', $packlistId)->value('status')
        );
        $this->assertDatabaseHas('fulfillment_removals', [
            'order_id' => $orderId,
            'stage' => 'packing',
        ]);
    }

    public function test_leaves_completed_packlist_untouched(): void
    {
        $orderId = $this->seedOrder('cancelled', true);
        $packlistId = $this->seedPacklist($orderId, Packlist::STATUS_COMPLETED);

        app(FulfillmentCleanupService::class)->detachCancelledOrder($orderId);

        // Packlist COMPLETED (sudah packed) di luar cakupan cleanup ini.
        $this->assertSame(
            Packlist::STATUS_COMPLETED,
            DB::table('packlists')->where('id', $packlistId)->value('status')
        );
    }

    public function test_guard_skips_non_cancelled_order(): void
    {
        $orderId = $this->seedOrder('reserved', false);
        $picklistId = $this->seedPicklist();
        $this->seedPicklistItemForOrder($picklistId, $orderId, 'SKU-D');

        app(FulfillmentCleanupService::class)->detachCancelledOrder($orderId);

        // Order belum dibatalkan → tak ada yang dihapus.
        $this->assertSame(1, DB::table('picklist_items')->where('order_id', $orderId)->count());
        $this->assertSame(0, DB::table('fulfillment_removals')->where('order_id', $orderId)->count());
    }
}
