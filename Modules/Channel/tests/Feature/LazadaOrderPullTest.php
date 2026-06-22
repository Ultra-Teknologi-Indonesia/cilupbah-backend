<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class LazadaOrderPullTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
        ]);

        $this->user = User::factory()->create();

        $lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $lazada->id,
            'shop_id' => 'LZ-100',
            'shop_name' => 'Toko Lazada',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    private function fakeOrdersApi(string $status = 'unpaid', string $sku = 'SKU-LZD-1', int $units = 2): void
    {
        Http::fake([
            'api.lazada.co.id/rest/orders/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'orders' => [[
                        'order_id' => 900123,
                        'order_number' => 900123,
                        'statuses' => [$status],
                        'price' => '150000.00',
                        'payment_method' => 'COD',
                        'shipping_fee' => 10000,
                        'voucher' => 0,
                        'created_at' => '2026-06-10 09:00:00 +0700',
                        'updated_at' => '2026-06-11 09:00:00 +0700',
                        'customer_first_name' => 'Budi',
                        'customer_last_name' => 'Santoso',
                        'remarks' => 'tolong packing rapi',
                        'address_shipping' => [
                            'first_name' => 'Budi', 'last_name' => 'Santoso', 'phone' => '0812000111',
                            'address1' => 'Jl. Mawar 1', 'city' => 'Bandung', 'post_code' => '40111', 'country' => 'ID',
                        ],
                    ]],
                ],
            ], 200),
            'api.lazada.co.id/rest/orders/items/get*' => Http::response([
                'code' => '0',
                'data' => [[
                    'order_id' => 900123,

                    'order_items' => array_fill(0, $units, [
                        'order_item_id' => 111,
                        'name' => 'Kaos Polos',
                        'variation' => 'Merah, L',
                        'sku' => $sku,
                        'item_price' => 70000,
                        'paid_price' => 70000,
                        'voucher_amount' => 0,
                        'tax_amount' => 0,
                        'tracking_code' => 'TRK-1',
                        'shipment_provider' => 'JNE',
                    ]),
                ]],
            ], 200),
        ]);
    }

    public function test_pull_creates_sales_order_with_grouped_items(): void
    {
        $this->fakeOrdersApi('unpaid');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100']);

        $response->assertStatus(200)->assertJsonPath('data.count', 1);

        $order = SalesOrder::where('salesorder_no', 'LZ-900123')->first();
        $this->assertNotNull($order);
        $this->assertEquals('lazada', $order->source);
        $this->assertEquals('pending', $order->status);          
        $this->assertEquals('Budi Santoso', $order->customer_name);
        $this->assertEquals(150000.0, (float) $order->grand_total);

        $items = DB::table('sales_order_items')->where('order_id', $order->id)->get();
        $this->assertCount(1, $items);
        $this->assertEquals(2, $items[0]->qty_in_base);
        $this->assertEquals('SKU-LZD-1', $items[0]->sku);
    }

    public function test_pull_is_idempotent(): void
    {
        $this->fakeOrdersApi('unpaid');

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])->assertStatus(200);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])->assertStatus(200);

        $this->assertEquals(1, SalesOrder::where('salesorder_no', 'LZ-900123')->count());
    }

    public function test_paid_order_reserves_stock_via_official_path(): void
    {

        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Kaos', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-LZD-1', 'sell_price' => 70000, 'is_active' => true]);
        $location = \Modules\Warehouse\Models\Location::factory()->create();
        DB::table('channel_warehouses')->insert([
            'channel_id' => $this->shop->channel_id,
            'store_id' => 'LZ-100',
            'channel_location_id' => 'LZ-WH-1',
            'location_id' => $location->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'on_hand' => 10, 'on_order' => 0, 'reserved' => 0, 'available' => 10,
        ]);

        $this->fakeOrdersApi('pending'); 

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])
            ->assertStatus(200)
            ->assertJsonPath('data.count', 1);

        $order = SalesOrder::where('salesorder_no', 'LZ-900123')->first();
        $this->assertEquals('reserved', $order->status);

        $inventory->refresh();
        $this->assertEquals(2, $inventory->reserved);
        $this->assertEquals(8, $inventory->available);
    }

    public function test_cancelled_order_maps_to_cancelled(): void
    {
        $this->fakeOrdersApi('canceled');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])
            ->assertStatus(200);

        $order = SalesOrder::where('salesorder_no', 'LZ-900123')->first();
        $this->assertEquals('cancelled', $order->status);
        $this->assertTrue((bool) $order->is_canceled);
    }

    public function test_expired_token_triggers_refresh_then_retry(): void
    {
        Http::fakeSequence('api.lazada.co.id/rest/orders/get*')
            ->push(['code' => 'IllegalAccessToken', 'message' => 'expired'], 200)
            ->push(['code' => '0', 'data' => ['orders' => []]], 200);

        Http::fake([
            'auth.lazada.com/rest/auth/token/refresh*' => Http::response([
                'access_token' => 'fresh-token', 'refresh_token' => 'fresh-refresh',
                'expires_in' => 25200, 'code' => '0',
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])
            ->assertStatus(200)
            ->assertJsonPath('data.count', 0);

        $this->assertEquals('fresh-token', $this->shop->refresh()->access_token);
    }

    public function test_unknown_shop_returns_422_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-GHOST'])
            ->assertStatus(422);
    }

    public function test_missing_shop_id_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/pull', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shop_id']);
    }

    public function test_pull_requires_auth(): void
    {
        $this->postJson('/api/v1/lazada/sync/pull', ['shop_id' => 'LZ-100'])->assertStatus(401);
    }

    public function test_pull_all_returns_per_shop_summary(): void
    {
        $this->fakeOrdersApi('unpaid');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/auto-sync/pull-orders')
            ->assertStatus(200)
            ->assertJsonPath('data.total_pulled', 1)
            ->assertJsonPath('data.shops.0.status', 'success');
    }
}
