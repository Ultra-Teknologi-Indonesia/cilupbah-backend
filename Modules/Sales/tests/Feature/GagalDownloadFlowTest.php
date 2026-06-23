<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class GagalDownloadFlowTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $service;
    protected SalesOrderRepository $repository;
    protected string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesOrderService::class);
        $this->repository = app(SalesOrderRepository::class);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-GD',
            'location_name' => 'Gudang Gagal Download',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedVariant(string $sku, int $onHand = 10): string
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

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => $onHand,
            'reserved' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variantId;
    }

    protected function channelOrderData(string $orderNo, string $sku): array
    {
        return [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-GD',
            'customer_name' => 'Buyer Gagal Download',
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
            'channel_status' => 'AWAITING_SHIPMENT',
            'status' => 'pending',
            'is_paid' => true,
            'payment_method' => null,
            'source' => 'shopee',
            'location_id' => $this->locationId,
            'items' => [[
                'channel_product_id' => 'CP-GD',
                'sku' => $sku,
                'description' => 'Item ' . $sku,
                'qty_in_base' => 2,
                'price' => 5000,
                'disc' => 0,
                'disc_amount' => 0,
                'tax_amount' => 0,
                'amount' => 10000,
            ]],
        ];
    }

    protected function freshOrder(string $orderId): SalesOrder
    {
        return SalesOrder::with('items')->findOrFail($orderId);
    }

    public function test_channel_order_with_unknown_sku_is_kept_but_unmapped(): void
    {

        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-1', 'SKU-ASING'));

        $order = $this->freshOrder($orderId);

        $this->assertNotNull($order);
        $this->assertCount(1, $order->items);
        $this->assertNull($order->items->first()->item_id, 'SKU asing harus tetap tersimpan tanpa item_id');

        $this->assertSame(0, DB::table('inventory_movements')->where('source', 'ORDER_RESERVE')->count());
    }

    public function test_unmapped_order_appears_in_failed_tab_not_ready_to_process(): void
    {
        $this->service->upsertFromChannel($this->channelOrderData('GD-2', 'SKU-ASING'));

        $counts = $this->repository->getTabCounts();

        $this->assertSame(1, $counts['failed'], 'order ber-SKU asing harus masuk tab Gagal Download');
        $this->assertSame(0, $counts['ready-to-process'], 'order ber-SKU asing tidak boleh masuk antrean Siap Proses');
    }

    public function test_download_binds_item_reserves_stock_and_moves_to_ready_to_process(): void
    {
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-3', 'SKU-DOWNLOAD'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        $variantId = $this->seedVariant('SKU-DOWNLOAD');

        $this->service->downloadOrderItem($order, $itemId);

        $item = DB::table('sales_order_items')->where('id', $itemId)->first();
        $this->assertSame($variantId, $item->item_id, 'item harus terpetakan ke variant master');

        $inv = DB::table('inventories')->where('item_id', $variantId)->where('location_id', $this->locationId)->first();
        $this->assertSame(2, $inv->reserved, 'stok harus ter-reserve setelah download');

        $counts = $this->repository->getTabCounts();
        $this->assertSame(0, $counts['failed']);
        $this->assertSame(1, $counts['ready-to-process']);
    }

    public function test_download_pulls_product_from_channel_then_binds(): void
    {
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-PULL', 'SKU-PULL'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        $this->mock(ChannelDownloadService::class, function ($mock) {
            $mock->shouldReceive('downloadProduct')
                ->once()
                ->andReturnUsing(function () {
                    $this->seedVariant('SKU-PULL');

                    return true;
                });
        });

        $this->service->downloadOrderItem($order, $itemId);

        $item = DB::table('sales_order_items')->where('id', $itemId)->first();
        $this->assertNotNull($item->item_id, 'item harus ter-bind setelah produk ditarik dari channel');

        $counts = $this->repository->getTabCounts();
        $this->assertSame(0, $counts['failed']);
        $this->assertSame(1, $counts['ready-to-process']);
    }

    public function test_download_keeps_order_quarantined_when_channel_pull_fails(): void
    {
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-4', 'SKU-ASING'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        $this->mock(ChannelDownloadService::class, function ($mock) {
            $mock->shouldReceive('downloadProduct')
                ->andThrow(new \RuntimeException('Produk tidak ditemukan'));
        });

        try {
            $this->service->downloadOrderItem($order, $itemId);
            $this->fail('Seharusnya melempar ProductNotMappableException');
        } catch (ProductNotMappableException $e) {

        }

        $counts = $this->repository->getTabCounts();
        $this->assertSame(1, $counts['failed'], 'order tetap di Gagal Download saat pull gagal');
    }

    public function test_download_is_idempotent_and_does_not_double_reserve(): void
    {
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-5', 'SKU-IDEMP'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        $variantId = $this->seedVariant('SKU-IDEMP');

        $this->service->downloadOrderItem($order, $itemId);

        $this->service->downloadOrderItem($this->freshOrder($orderId), $itemId);

        $inv = DB::table('inventories')->where('item_id', $variantId)->first();
        $this->assertSame(2, $inv->reserved, 'reserve tidak boleh terjadi dua kali');
        $this->assertSame(1, DB::table('inventory_movements')->where('source', 'ORDER_RESERVE')->count());
    }

    public function test_download_endpoint_maps_item_and_returns_order(): void
    {
        $user = User::factory()->create();

        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-6', 'SKU-HTTP'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        $this->seedVariant('SKU-HTTP');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$orderId}/items/{$itemId}/download");

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_unmapped_items', false);

        $item = DB::table('sales_order_items')->where('id', $itemId)->first();
        $this->assertNotNull($item->item_id);
    }

    public function test_index_failed_tab_lists_unmapped_order_via_http(): void
    {
        $user = User::factory()->create();
        $this->service->upsertFromChannel($this->channelOrderData('GD-IDX', 'SKU-ASING'));

        $failed = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?tab=failed');
        $failed->assertStatus(200);
        $this->assertCount(1, $failed->json('data'));

        $ready = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?tab=ready-to-process');
        $ready->assertStatus(200);
        $this->assertCount(0, $ready->json('data'));
    }

    public function test_index_defaults_to_ten_per_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
    }

    public function test_index_supports_legacy_sort_and_search_params(): void
    {
        $user = User::factory()->create();
        $this->seedVariant('SKU-IDX-A');
        $this->seedVariant('SKU-IDX-B');
        $idA = $this->service->upsertFromChannel($this->channelOrderData('IDX-A', 'SKU-IDX-A'));
        $this->service->upsertFromChannel($this->channelOrderData('IDX-B', 'SKU-IDX-B'));
        DB::table('sales_orders')->where('id', $idA)->update(['customer_name' => 'Zulkarnain']);

        $sorted = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales?sort_by=salesorder_no&sort_dir=asc');
        $sorted->assertStatus(200);
        $nos = array_column($sorted->json('data'), 'salesorder_no');
        $this->assertSame($nos, collect($nos)->sort()->values()->all(), 'urutan harus naik by salesorder_no');

        $search = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?q=Zulkarnain');
        $search->assertStatus(200);
        $this->assertCount(1, $search->json('data'));
        $this->assertSame('IDX-A', $search->json('data.0.salesorder_no'));
    }

    public function test_index_sku_search_filters_by_item_sku(): void
    {
        $user = User::factory()->create();
        $this->seedVariant('KAOS-MERAH');
        $this->seedVariant('CELANA-BIRU');
        $this->service->upsertFromChannel($this->channelOrderData('SKU-1', 'KAOS-MERAH'));
        $this->service->upsertFromChannel($this->channelOrderData('SKU-2', 'CELANA-BIRU'));

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales?search_by=sku&q=KAOS-MERAH');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('SKU-1', $res->json('data.0.salesorder_no'));
    }
}
