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

    /**
     * Create a Master Produk variant (+ inventory) for a SKU. Simulates the product
     * being present/downloaded into Master Produk so it can be bound.
     */
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
        // SKU 'SKU-ASING' has no Master Produk variant.
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-1', 'SKU-ASING'));

        $order = $this->freshOrder($orderId);

        // Order is NOT rejected — it exists with the item kept and item_id null.
        $this->assertNotNull($order);
        $this->assertCount(1, $order->items);
        $this->assertNull($order->items->first()->item_id, 'SKU asing harus tetap tersimpan tanpa item_id');

        // No stock reserved anywhere for the un-mapped item.
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

        // Operator "downloads" the product into Master Produk, then binds.
        $variantId = $this->seedVariant('SKU-DOWNLOAD');

        $this->service->downloadOrderItem($order, $itemId);

        $item = DB::table('sales_order_items')->where('id', $itemId)->first();
        $this->assertSame($variantId, $item->item_id, 'item harus terpetakan ke variant master');

        // Stock reserved for the now-mapped item.
        $inv = DB::table('inventories')->where('item_id', $variantId)->where('location_id', $this->locationId)->first();
        $this->assertSame(2, $inv->reserved, 'stok harus ter-reserve setelah download');

        // Tab transition: leaves Gagal Download, enters Siap Proses.
        $counts = $this->repository->getTabCounts();
        $this->assertSame(0, $counts['failed']);
        $this->assertSame(1, $counts['ready-to-process']);
    }

    public function test_download_pulls_product_from_channel_then_binds(): void
    {
        $orderId = $this->service->upsertFromChannel($this->channelOrderData('GD-PULL', 'SKU-PULL'));
        $order = $this->freshOrder($orderId);
        $itemId = $order->items->first()->id;

        // Product is NOT in master yet. Simulate the marketplace "Download": the channel
        // service pulls it into master (creates the variant) and returns true.
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

        // Channel pull fails (product gone / shop error): order must stay in Gagal Download.
        $this->mock(ChannelDownloadService::class, function ($mock) {
            $mock->shouldReceive('downloadProduct')
                ->andThrow(new \RuntimeException('Produk tidak ditemukan'));
        });

        try {
            $this->service->downloadOrderItem($order, $itemId);
            $this->fail('Seharusnya melempar ProductNotMappableException');
        } catch (ProductNotMappableException $e) {
            // expected
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
        // Second call should be a no-op (already mapped) — no extra reservation.
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
}
