<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class SyncOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SalesOrderRepository::class);
    }

    protected function createVariant(string $sku): string
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori ' . $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk ' . $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variantId;
    }

    protected function createOrder(string $orderNo = 'LZ-1001'): string
    {
        $order = $this->repository->upsertOrderBySalesOrderNo($orderNo, [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Test',
            'transaction_date' => now(),
            'sub_total' => 30000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 30000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => 'UNPAID',
            'status' => 'pending',
            'is_paid' => false,
            'payment_method' => null,
            'source' => 'lazada',
        ]);

        return $order->id;
    }

    protected function item(string $sku, int $qty, float $price): array
    {
        return [
            'channel_product_id' => 'CP-' . $sku,
            'sku' => $sku,
            'description' => 'Item ' . $sku,
            'qty_in_base' => $qty,
            'price' => $price,
            'disc' => 0,
            'disc_amount' => 0,
            'tax_amount' => 0,
            'amount' => $qty * $price,
        ];
    }

    protected function createPicklistReferencing(string $orderId, string $orderItemId, string $variantId, string $sku): void
    {
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-' . Str::random(6),
            'location_name' => 'Gudang Test',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PL-' . Str::random(8),
            'location_id' => $locationId,
            'status' => 'IN_PROGRESS',
            'created_by' => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $variantId,
            'sku' => $sku,
            'qty_ordered' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_initial_sync_inserts_items_and_resolves_variant(): void
    {
        $variantId = $this->createVariant('SKU-A');
        $orderId = $this->createOrder();

        $this->repository->syncOrderItems($orderId, [
            $this->item('SKU-A', 2, 10000),
            $this->item('SKU-X', 1, 5000),
        ]);

        $rows = DB::table('sales_order_items')->where('order_id', $orderId)->get();
        $this->assertCount(2, $rows);
        $this->assertSame($variantId, $rows->firstWhere('sku', 'SKU-A')->item_id);
        $this->assertNull($rows->firstWhere('sku', 'SKU-X')->item_id);
    }

    public function test_resync_updates_in_place_without_changing_row_ids(): void
    {
        $this->createVariant('SKU-A');
        $orderId = $this->createOrder();

        $this->repository->syncOrderItems($orderId, [$this->item('SKU-A', 2, 10000)]);
        $originalId = DB::table('sales_order_items')->where('order_id', $orderId)->value('id');

        $this->repository->syncOrderItems($orderId, [$this->item('SKU-A', 5, 10000)]);

        $rows = DB::table('sales_order_items')->where('order_id', $orderId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($originalId, $rows->first()->id);
        $this->assertSame(5, $rows->first()->qty_in_base);
    }

    public function test_resync_adds_new_and_removes_missing_items(): void
    {
        $this->createVariant('SKU-A');
        $orderId = $this->createOrder();

        $this->repository->syncOrderItems($orderId, [
            $this->item('SKU-A', 1, 10000),
            $this->item('SKU-B', 1, 7000),
        ]);

        $this->repository->syncOrderItems($orderId, [
            $this->item('SKU-A', 1, 10000),
            $this->item('SKU-C', 2, 3000),
        ]);

        $rows = DB::table('sales_order_items')->where('order_id', $orderId)->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows->firstWhere('sku', 'SKU-A'));
        $this->assertNotNull($rows->firstWhere('sku', 'SKU-C'));
        $this->assertNull($rows->firstWhere('sku', 'SKU-B'));
    }

    public function test_duplicate_per_unit_rows_are_matched_by_pool(): void
    {
        $this->createVariant('SKU-A');
        $orderId = $this->createOrder();

        $items = [$this->item('SKU-A', 1, 10000), $this->item('SKU-A', 1, 10000)];

        $this->repository->syncOrderItems($orderId, $items);
        $idsBefore = DB::table('sales_order_items')->where('order_id', $orderId)->orderBy('id')->pluck('id');

        $this->repository->syncOrderItems($orderId, $items);
        $idsAfter = DB::table('sales_order_items')->where('order_id', $orderId)->orderBy('id')->pluck('id');

        $this->assertCount(2, $idsAfter);
        $this->assertEquals($idsBefore->all(), $idsAfter->all());
    }

    public function test_item_referenced_by_picklist_survives_resync_without_fk_error(): void
    {
        $variantId = $this->createVariant('SKU-A');
        $this->createVariant('SKU-B');
        $orderId = $this->createOrder();

        $this->repository->syncOrderItems($orderId, [
            $this->item('SKU-A', 1, 10000),
            $this->item('SKU-B', 1, 7000),
        ]);

        $pickedItem = DB::table('sales_order_items')
            ->where('order_id', $orderId)->where('sku', 'SKU-A')->first();
        $this->createPicklistReferencing($orderId, $pickedItem->id, $variantId, 'SKU-A');

        $this->repository->syncOrderItems($orderId, [$this->item('SKU-B', 1, 7000)]);

        $rows = DB::table('sales_order_items')->where('order_id', $orderId)->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows->firstWhere('sku', 'SKU-A'), 'Item yang dipegang picklist harus dipertahankan');
        $this->assertNotNull($rows->firstWhere('sku', 'SKU-B'));
    }

    public function test_full_channel_upsert_succeeds_for_order_already_in_picking(): void
    {
        $variantId = $this->createVariant('SKU-A');
        $orderId = $this->createOrder('LZ-2002');

        $this->repository->syncOrderItems($orderId, [$this->item('SKU-A', 1, 10000)]);

        $orderItem = DB::table('sales_order_items')->where('order_id', $orderId)->first();
        $this->createPicklistReferencing($orderId, $orderItem->id, $variantId, 'SKU-A');

        $service = app(SalesOrderService::class);

        $resultId = $service->upsertFromChannel([
            'salesorder_no' => 'LZ-2002',
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Test',
            'transaction_date' => now(),
            'sub_total' => 10000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 10000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => 'UNPAID',
            'status' => 'pending',
            'is_paid' => false,
            'payment_method' => null,
            'source' => 'lazada',
            'items' => [$this->item('SKU-A', 1, 10000)],
        ]);

        $this->assertSame($orderId, $resultId);
        $this->assertDatabaseHas('sales_order_items', ['id' => $orderItem->id, 'order_id' => $orderId]);
    }
}
