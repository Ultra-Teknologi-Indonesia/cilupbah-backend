<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Tests\TestCase;

class SalesOrderUniversalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    private function createTestOrder(array $orderAttributes = [], array $items = []): SalesOrder
    {
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id'            => $locationId,
            'location_code' => 'LOC-' . Str::upper(Str::random(6)),
            'location_name' => 'Gudang Utama',
            'location_type' => 'WAREHOUSE',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $order = SalesOrder::create(array_merge([
            'salesorder_no'    => 'SO-' . Str::upper(Str::random(8)),
            'channel_order_no' => 'TT-' . rand(100000000000, 999999999999),
            'customer_name'    => 'Pelanggan Setia',
            'source'           => 'tiktok',
            'location_id'      => $locationId,
            'status'           => 'shipped',
            'is_paid'          => true,
            'tracking_number'  => 'RESI-' . Str::upper(Str::random(8)),
            'transaction_date' => now(),
        ], $orderAttributes));

        foreach ($items as $item) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Kategori Utama',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemId = Str::uuid()->toString();
            $productId = Str::uuid()->toString();
            DB::table('products')->insert([
                'id' => $productId,
                'category_id' => $categoryId,
                'name' => $item['description'] ?? 'Produk Utama',
                'sku' => $item['sku'] ?? 'SKU-PARENT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('product_variants')->insert([
                'id' => $itemId,
                'product_id' => $productId,
                'sku' => $item['sku'] ?? 'SKU-' . Str::upper(Str::random(6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            SalesOrderItem::create(array_merge([
                'order_id'    => $order->id,
                'item_id'     => $itemId,
                'sku'         => 'SKU-' . Str::upper(Str::random(6)),
                'description' => 'Produk Deskripsi',
                'qty_in_base' => 1,
                'price'       => 50000,
                'amount'      => 50000,
            ], $item));
        }

        return $order;
    }

    public function test_search_by_q_param_finds_order_by_channel_order_no(): void
    {
        $targetOrder = $this->createTestOrder([
            'channel_order_no' => 'TT-585568401271129929',
        ]);
        $this->createTestOrder([
            'channel_order_no' => 'TT-111111111111111111',
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=TT-585568401271129929')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($targetOrder->id, $data[0]['id']);
    }

    public function test_search_by_search_param_finds_order_by_salesorder_no(): void
    {
        $targetOrder = $this->createTestOrder([
            'salesorder_no' => 'SO-TARGET-2026',
        ]);
        $this->createTestOrder([
            'salesorder_no' => 'SO-OTHER-2026',
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?search=SO-TARGET-2026')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($targetOrder->id, $data[0]['id']);
    }

    public function test_universal_search_finds_order_by_item_sku(): void
    {
        $targetOrder = $this->createTestOrder([], [
            ['sku' => 'SKU-SPECIAL-SHIRT', 'description' => 'Kaos Polos'],
        ]);
        $this->createTestOrder([], [
            ['sku' => 'SKU-OTHER-PANTS', 'description' => 'Celana Jeans'],
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=SKU-SPECIAL-SHIRT')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($targetOrder->id, $data[0]['id']);
    }

    public function test_universal_search_finds_order_by_item_product_name(): void
    {
        $targetOrder = $this->createTestOrder([], [
            ['sku' => 'SKU-100', 'description' => 'Sepatu Sneakers Running Pro Max'],
        ]);
        $this->createTestOrder([], [
            ['sku' => 'SKU-200', 'description' => 'Topi Fedora Casual'],
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=Sneakers+Running')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($targetOrder->id, $data[0]['id']);
    }

    public function test_search_by_customer_name_and_tracking_number(): void
    {
        $order1 = $this->createTestOrder([
            'customer_name' => 'Budi Santoso Surabaya',
        ]);
        $order2 = $this->createTestOrder([
            'tracking_number' => 'RESI-JX-99887766',
        ]);

        $res1 = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=Budi+Santoso')
            ->assertOk();
        $this->assertSame($order1->id, $res1->json('data.0.id'));

        $res2 = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=RESI-JX-99887766')
            ->assertOk();
        $this->assertSame($order2->id, $res2->json('data.0.id'));
    }

    public function test_explicit_search_by_sku_only_matches_items(): void
    {
        $orderWithSku = $this->createTestOrder([
            'salesorder_no' => 'SO-MATCH-1',
        ], [
            ['sku' => 'SKU-TARGET-ABC', 'description' => 'Target Item'],
        ]);

        $orderWithMatchingNo = $this->createTestOrder([
            'salesorder_no' => 'SKU-TARGET-ABC',
        ], [
            ['sku' => 'OTHER-SKU', 'description' => 'Other Item'],
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=SKU-TARGET-ABC&search_by=sku')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($orderWithSku->id, $data[0]['id']);
    }

    public function test_explicit_search_by_order_only_matches_header_columns(): void
    {
        $orderWithSku = $this->createTestOrder([
            'salesorder_no' => 'SO-UNMATCHED-1',
        ], [
            ['sku' => 'SO-ORDER-TARGET-1', 'description' => 'Target Item'],
        ]);

        $orderWithHeader = $this->createTestOrder([
            'salesorder_no' => 'SO-ORDER-TARGET-1',
        ], [
            ['sku' => 'OTHER-SKU', 'description' => 'Other Item'],
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales?q=SO-ORDER-TARGET-1&search_by=order')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($orderWithHeader->id, $data[0]['id']);
    }
}
