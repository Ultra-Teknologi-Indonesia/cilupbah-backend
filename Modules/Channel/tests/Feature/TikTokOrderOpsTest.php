<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

/**
 * Memastikan setiap aksi order TikTok (accept / ship / decline / buyer-cancel /
 * cancel) benar-benar memanggil API marketplace DAN memicu perubahan status lokal
 * lewat resync (pola yang sama dengan Shopee/Lazada).
 */
class TikTokOrderOpsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;

    private const ORDER_ID = '5760001';
    private const SALES_NO = 'TT-5760001';
    private const BASE = 'open-api.tiktokglobalshop.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_key' => 'test_key',
            'services.tiktok.app_secret' => 'test_secret',
        ]);

        $this->user = User::factory()->create();
        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'TT-700',
            'shop_name' => 'Toko TikTok',
            'shop_cipher' => 'CIPHER-XYZ',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ]);
    }

    /**
     * Detail order yang dipakai semua endpoint GET /order/202309/orders
     * (resolve package id, resync, dan fetch tracking).
     */
    private function orderDetail(string $status): array
    {
        return [
            'code' => 0,
            'data' => ['orders' => [[
                'id'                => self::ORDER_ID,
                'status'            => $status,
                'create_time'       => 1718000000,
                'buyer_email'       => 'buyer@example.com',
                'recipient_address' => ['name' => 'Budi', 'full_address' => 'Jl. Mawar 1'],
                'payment'           => ['total_amount' => '100000', 'original_total_product_price' => '100000'],
                'packages'          => [[
                    'id'                     => 'PKG-1',
                    'tracking_number'        => 'TTRK-1',
                    'shipping_provider_name' => 'TikTok Logistics',
                ]],
                'line_items'        => [[
                    'product_id'    => 'P1',
                    'sku_id'        => 'S1',
                    'product_name'  => 'Kaos',
                    'quantity'      => 1,
                    'original_price' => '100000',
                ]],
            ]]],
        ];
    }

    private function seedLocalOrder(string $channelStatus, string $status): SalesOrder
    {
        return SalesOrder::create([
            'salesorder_no'   => self::SALES_NO,
            'channel_order_no' => self::ORDER_ID,
            'channel_shop_id' => 'TT-700',
            'customer_name'   => 'Budi',
            'source'          => 'tiktok',
            'channel_status'  => $channelStatus,
            'status'          => $status,
            'sub_total'       => 100000,
            'total_disc'      => 0,
            'total_tax'       => 0,
            'shipping_cost'   => 0,
            'insurance_cost'  => 0,
            'grand_total'     => 100000,
            'is_paid'         => true,
        ]);
    }

    public function test_accept_order_hits_packages_api_and_syncs_status(): void
    {
        Http::fake([
            self::BASE . '/fulfillment/202309/packages*' => Http::response(['code' => 0, 'data' => ['package_id' => 'PKG-1']], 200),
            self::BASE . '/order/202309/orders*' => Http::response($this->orderDetail('AWAITING_SHIPMENT'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/accept', ['shop_id' => 'TT-700', 'order_id' => self::ORDER_ID])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/fulfillment/202309/packages') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertNotNull($order);
        $this->assertEquals('AWAITING_SHIPMENT', $order->channel_status);
        $this->assertEquals('reserved', $order->status);
    }

    public function test_ship_order_hits_ship_api_and_status_becomes_packed(): void
    {
        Http::fake([
            self::BASE . '/fulfillment/202309/packages/*/ship*' => Http::response(['code' => 0, 'data' => ['package_id' => 'PKG-1']], 200),
            self::BASE . '/fulfillment/202309/packages*' => Http::response(['code' => 0, 'data' => []], 200),
            self::BASE . '/order/202309/orders*' => Http::response($this->orderDetail('AWAITING_COLLECTION'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/ship', ['shop_id' => 'TT-700', 'order_id' => self::ORDER_ID])
            ->assertStatus(200)
            ->assertJsonPath('data.shipped', true);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/fulfillment/202309/packages/PKG-1/ship') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertNotNull($order);
        $this->assertEquals('AWAITING_COLLECTION', $order->channel_status);
        $this->assertEquals('packed', $order->status);
        $this->assertEquals('TTRK-1', $order->tracking_number);
    }

    public function test_decline_order_hits_cancellation_api_and_status_cancelled(): void
    {
        Http::fake([
            self::BASE . '/return_refund/202309/cancellations*' => Http::response(['code' => 0, 'data' => ['cancel_id' => 'C1']], 200),
            self::BASE . '/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/decline', ['shop_id' => 'TT-700', 'order_id' => self::ORDER_ID, 'reason' => 'out_of_stock'])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/return_refund/202309/cancellations') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertNotNull($order);
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_handle_buyer_cancel_accept_hits_approve_and_cancels(): void
    {
        Http::fake([
            self::BASE . '/return_refund/202309/cancellations/approve*' => Http::response(['code' => 0, 'data' => []], 200),
            self::BASE . '/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/handle-buyer-cancel', [
                'shop_id' => 'TT-700', 'order_id' => self::ORDER_ID, 'operation' => 'ACCEPT',
            ])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/return_refund/202309/cancellations/approve') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_cancel_order_passes_guard_hits_api_and_cancels(): void
    {
        $this->seedLocalOrder('AWAITING_SHIPMENT', 'reserved');

        Http::fake([
            self::BASE . '/return_refund/202309/cancellations*' => Http::response(['code' => 0, 'data' => ['cancel_id' => 'C1']], 200),
            self::BASE . '/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/cancel', ['order_id' => self::ORDER_ID, 'cancel_reason' => 'out_of_stock'])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/return_refund/202309/cancellations') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_cancel_order_rejected_when_status_not_cancelable(): void
    {
        $this->seedLocalOrder('AWAITING_COLLECTION', 'packed');

        Http::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/cancel', ['order_id' => self::ORDER_ID, 'cancel_reason' => 'out_of_stock'])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/return_refund/202309/cancellations'));

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertEquals('packed', $order->status);
    }

    public function test_ops_require_auth(): void
    {
        $this->postJson('/api/v1/tiktok/sync/accept', [])->assertStatus(401);
        $this->postJson('/api/v1/tiktok/sync/ship', [])->assertStatus(401);
        $this->postJson('/api/v1/tiktok/sync/decline', [])->assertStatus(401);
        $this->postJson('/api/v1/tiktok/sync/cancel', [])->assertStatus(401);
        $this->postJson('/api/v1/tiktok/sync/handle-buyer-cancel', [])->assertStatus(401);
    }
}
