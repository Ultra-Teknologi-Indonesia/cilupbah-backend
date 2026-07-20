<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class LazadaOrderOpsTest extends TestCase
{
    use RefreshDatabase;
    use \Modules\Channel\Tests\Support\SeedsCatalogVariant;

    private User $user;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCatalogVariant('SKU-A');

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

    private function fakeItemsForOrder(array $items, string $statusAfter = 'packed', array $extra = []): void
    {
        Http::fake(array_merge([
            'api.lazada.co.id/rest/orders/items/get*' => Http::response([
                'code' => '0',
                'data' => [[
                    'order_id' => 900123,
                    'order_items' => $items,
                ]],
            ], 200),
            'api.lazada.co.id/rest/order/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'order_id' => 900123,
                    'statuses' => [$statusAfter],
                    'price' => '100000.00',
                    'created_at' => '2026-06-10 09:00:00 +0700',
                    'customer_first_name' => 'Budi',
                ],
            ], 200),
        ], $extra));
    }

    public function test_fulfill_pack_only_includes_pending_and_repacked_items(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'status' => 'pending', 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
            ['order_item_id' => 112, 'status' => 'repacked', 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
            ['order_item_id' => 113, 'status' => 'packed', 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
        ], extra: [
            'api.lazada.co.id/rest/order/fulfill/pack*' => Http::response(['code' => '0', 'data' => ['success' => true]], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/fulfill-pack', [
            'shop_id' => 'LZ-100',
            'order_id' => '900123',
            'shipping_provider_id' => 'LEX-ID',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.order_item_ids', ['111', '112']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/order/fulfill/pack')
            && ($r['order_item_ids'] ?? null) === '["111","112"]'
            && ($r['shipping_provider_id'] ?? null) === 'LEX-ID');
    }

    public function test_fulfill_pack_rejects_when_no_packable_items(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'status' => 'packed'],
        ]);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/fulfill-pack', [
            'shop_id' => 'LZ-100',
            'order_id' => '900123',
            'shipping_provider_id' => 'LEX-ID',
        ])->assertStatus(422);
    }

    public function test_ready_to_ship_only_includes_packed_items(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'status' => 'packed', 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
            ['order_item_id' => 112, 'status' => 'pending', 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
        ], statusAfter: 'ready_to_ship', extra: [
            'api.lazada.co.id/rest/order/package/rts*' => Http::response(['code' => '0', 'data' => ['success' => true]], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/rts', [
            'shop_id' => 'LZ-100',
            'order_id' => '900123',
            'tracking_number' => 'LZDTRK-9',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.order_item_ids', ['111']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/order/package/rts')
            && ($r['order_item_ids'] ?? null) === '["111"]'
            && ($r['tracking_number'] ?? null) === 'LZDTRK-9');
    }

    public function test_ready_to_ship_rejects_when_no_packed_items(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'status' => 'pending'],
        ]);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/rts', [
            'shop_id' => 'LZ-100',
            'order_id' => '900123',
        ])->assertStatus(422);
    }

    public function test_print_awb_returns_document_when_ready(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'status' => 'packed'],
        ], extra: [
            'api.lazada.co.id/rest/order/document/get*' => Http::response([
                'code' => '0',
                'data' => ['document' => ['doc_type' => 'shippingLabel', 'file' => base64_encode('PDF')]],
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/lazada/sync/awb?shop_id=LZ-100&order_id=900123')
            ->assertStatus(200)
            ->assertJsonPath('data.document.doc_type', 'shippingLabel');
    }

    public function test_cancel_order_cancels_each_item_and_syncs_local_status(): void
    {
        $this->fakeItemsForOrder([
            ['order_item_id' => 111, 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
            ['order_item_id' => 112, 'name' => 'Kaos', 'sku' => 'SKU-A', 'item_price' => 50000, 'paid_price' => 50000],
        ], statusAfter: 'canceled', extra: [
            'api.lazada.co.id/rest/order/cancel*' => Http::response(['code' => '0', 'data' => ['success' => true]], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/cancel', [
            'shop_id' => 'LZ-100',
            'order_id' => '900123',
            'reason_id' => '15',
            'reason_detail' => 'Stok habis',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.cancelled_item_ids', ['111', '112']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/order/cancel') && ($r['order_item_id'] ?? null) === '111');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/order/cancel') && ($r['order_item_id'] ?? null) === '112');

        $order = SalesOrder::where('salesorder_no', 'LZ-900123')->first();
        $this->assertNotNull($order);
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_cancel_reasons_returns_list(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/order/failure_reason/get*' => Http::response([
                'code' => '0',
                'data' => [['reason_id' => 15, 'reason_name' => 'Out of stock']],
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/lazada/cancel-reasons?shop_id=LZ-100')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reason_id', 15);
    }

    public function test_logistics_returns_shipment_providers(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/shipment/providers/get*' => Http::response([
                'code' => '0',
                'data' => ['shipment_providers' => [['name' => 'LEX ID'], ['name' => 'JNE']]],
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/lazada/logistics?shop_id=LZ-100')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'LEX ID')
            ->assertJsonCount(2, 'data');
    }

    public function test_fulfill_pack_unknown_shop_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/fulfill-pack', [
                'shop_id' => 'LZ-GHOST',
                'order_id' => '1',
                'shipping_provider_id' => 'LEX-ID',
            ])
            ->assertStatus(422);
    }

    public function test_cancel_missing_reason_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/cancel', ['shop_id' => 'LZ-100', 'order_id' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason_id']);
    }

    public function test_lazada_api_error_returns_422_not_500(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response(['code' => '500', 'message' => 'internal'], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/fulfill-pack', [
                'shop_id' => 'LZ-100',
                'order_id' => '900123',
                'shipping_provider_id' => 'LEX-ID',
            ])
            ->assertStatus(422);
    }

    public function test_ops_require_auth(): void
    {
        $this->postJson('/api/v1/lazada/sync/fulfill-pack', [])->assertStatus(401);
        $this->postJson('/api/v1/lazada/sync/rts', [])->assertStatus(401);
        $this->postJson('/api/v1/lazada/sync/cancel', [])->assertStatus(401);
        $this->getJson('/api/v1/lazada/logistics?shop_id=x')->assertStatus(401);
    }
}
