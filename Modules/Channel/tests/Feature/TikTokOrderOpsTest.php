<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Tests\Support\SeedsCatalogVariant;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class TikTokOrderOpsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogVariant;

    private User $user;

    private ChannelShop $shop;

    private const ORDER_ID = '5760001';

    private const SALES_NO = 'TT-5760001';

    private const BASE = 'open-api.tiktokglobalshop.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCatalogVariant('TK-S1');

        config([
            'services.tiktok.app_key' => 'test_key',
            'services.tiktok.app_secret' => 'test_secret',
        ]);

        $this->user = $this->createPrivilegedUser();
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

    public function test_fetch_return_detail_parses_tiktok_money_objects(): void
    {
        $returnId = '4041827037794371092';

        Http::fake([
            self::BASE.'/return_refund/202309/returns/search*' => Http::response([
                'code' => 0,
                'data' => ['return_orders' => [[
                    'return_id' => $returnId,
                    'order_id' => self::ORDER_ID,
                    'return_status' => 'RETURN_OR_REFUND_REQUEST_PENDING',
                    'return_reason' => 'buyer_return_and_refund_suspected_counterfeit',
                    'return_reason_text' => 'Suspected counterfeit',
                    'update_time' => 1718000000,
                    'refund_amount' => [
                        'currency' => 'IDR',
                        'refund_subtotal' => '35000',
                        'refund_shipping_fee' => '0',
                        'refund_tax' => '0',
                        'buyer_service_fee' => '1000',
                        'refund_total' => '36360',
                    ],
                    'shipping_fee_amount' => [[
                        'currency' => 'IDR',
                        'buyer_paid_return_shipping_fee' => '0',
                        'platform_paid_return_shipping_fee' => '0',
                        'seller_paid_return_shipping_fee' => '32000',
                    ]],
                ]]],
            ], 200),
        ]);

        $detail = app(TikTokOrderService::class)
            ->fetchReturnDetail('TT-700', $returnId);

        $this->assertSame(36360.0, $detail['refund_amount']);
        $this->assertSame('IDR', $detail['refund_currency']);
        $this->assertSame(32000.0, $detail['shipping_fee_return']);
        $this->assertSame('buyer_return_and_refund_suspected_counterfeit', $detail['reason_code']);
        $this->assertSame('Suspected counterfeit', $detail['reason_text']);
        $this->assertSame('RETURN_OR_REFUND_REQUEST_PENDING', $detail['channel_status']);
    }

    private function orderDetail(
        string $status,
        ?string $trackingNumber = 'TTRK-1',
        ?string $packageStatus = null,
    ): array {
        return [
            'code' => 0,
            'data' => ['orders' => [[
                'id' => self::ORDER_ID,
                'status' => $status,
                'create_time' => 1718000000,
                'buyer_email' => 'buyer@example.com',
                'recipient_address' => ['name' => 'Budi', 'full_address' => 'Jl. Mawar 1'],
                'payment' => ['total_amount' => '100000', 'original_total_product_price' => '100000'],
                'packages' => [[
                    'id' => 'PKG-1',
                    'tracking_number' => $trackingNumber,
                    'shipping_provider_name' => 'TikTok Logistics',
                    'status' => $packageStatus,
                ]],
                'line_items' => [[
                    'product_id' => 'P1',
                    'sku_id' => 'S1',
                    'product_name' => 'Kaos',
                    'quantity' => 1,
                    'original_price' => '100000',
                ]],
            ]]],
        ];
    }

    private function seedLocalOrder(string $channelStatus, string $status): SalesOrder
    {
        return SalesOrder::create([
            'salesorder_no' => self::SALES_NO,
            'channel_order_no' => self::ORDER_ID,
            'channel_shop_id' => 'TT-700',
            'customer_name' => 'Budi',
            'source' => 'tiktok',
            'channel_status' => $channelStatus,
            'status' => $status,
            'sub_total' => 100000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 100000,
            'is_paid' => true,
        ]);
    }

    public function test_accept_order_hits_packages_api_and_syncs_status(): void
    {
        Http::fake([
            self::BASE.'/fulfillment/202309/packages*' => Http::response(['code' => 0, 'data' => ['package_id' => 'PKG-1']], 200),
            self::BASE.'/order/202309/orders*' => Http::response($this->orderDetail('AWAITING_SHIPMENT'), 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/accept', ['shop_id' => 'TT-700', 'order_id' => self::ORDER_ID])
            ->assertStatus(200);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/fulfillment/202309/packages') && $r->method() === 'POST');

        $order = SalesOrder::where('salesorder_no', self::SALES_NO)->first();
        $this->assertNotNull($order);
        $this->assertEquals('READY_TO_SHIP', $order->channel_status);
        $this->assertEquals('pending', $order->status, 'SKU belum di-download sehingga order masuk Gagal Download');
    }

    public function test_ship_order_hits_ship_api_and_status_becomes_packed(): void
    {
        $ready = $this->orderDetail('AWAITING_SHIPMENT', null, 'AWAITING_SHIPMENT');
        $accepted = $this->orderDetail('AWAITING_COLLECTION', 'TTRK-1', 'AWAITING_COLLECTION');

        Http::fake([
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response(['code' => 0, 'data' => ['package_id' => 'PKG-1']], 200),
            self::BASE.'/fulfillment/202309/packages*' => Http::response(['code' => 0, 'data' => []], 200),
            self::BASE.'/order/202309/orders*' => Http::sequence()
                ->push($ready, 200)
                ->push($accepted, 200)
                ->push($accepted, 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/ship', ['shop_id' => 'TT-700', 'order_id' => self::ORDER_ID])
            ->assertStatus(200)
            ->assertJsonPath('data.shipped', true);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/fulfillment/202309/packages/ship')
            && $request['packages'] === [['id' => 'PKG-1']]);

    }

    public function test_request_tracking_number_uses_known_package_and_reads_once(): void
    {
        $order = $this->seedLocalOrder('READY_TO_SHIP', 'pending');
        $order->update(['channel_package_ids' => ['PKG-1']]);

        Http::fake([
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response([
                'code' => 0,
                'data' => ['package_id' => 'PKG-1'],
            ], 200),
            self::BASE.'/order/202309/orders*' => Http::sequence()
                ->push($this->orderDetail('AWAITING_SHIPMENT', null, 'AWAITING_SHIPMENT'), 200)
                ->push($this->orderDetail('AWAITING_COLLECTION', 'TTRK-1', 'AWAITING_COLLECTION'), 200),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            null,
            ['PKG-1'],
        );

        $this->assertTrue($result['shipped']);
        $this->assertSame('TTRK-1', $result['tracking_number']);
        $this->assertSame('TTRK-1', $order->refresh()->tracking_number);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/fulfillment/202309/packages'));
    }

    public function test_request_tracking_number_reuses_the_verified_preflight_snapshot(): void
    {
        $order = $this->seedLocalOrder('READY_TO_SHIP', 'pending');
        $order->update(['channel_package_ids' => ['PKG-1']]);

        $snapshot = [
            'order_found' => true,
            'status' => 'AWAITING_SHIPMENT',
            'packages' => [[
                'id' => 'PKG-1',
                'tracking_number' => null,
                'shipping_provider' => 'TikTok Logistics',
                'status' => 'AWAITING_SHIPMENT',
            ]],
            'tracking_number' => null,
            'shipping_provider' => null,
            'has_pending_package' => true,
            'all_packages_shipped' => false,
        ];

        Http::fake([
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response([
                'code' => 0,
                'data' => ['package_id' => 'PKG-1'],
            ], 200),
            self::BASE.'/order/202309/orders*' => Http::response(
                $this->orderDetail('AWAITING_COLLECTION', 'TTRK-1', 'AWAITING_COLLECTION'),
                200,
            ),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            null,
            ['PKG-1'],
            $snapshot,
        );

        $this->assertTrue($result['shipped']);
        $this->assertSame('TTRK-1', $result['tracking_number']);

        Http::assertSentCount(2);
    }

    public function test_request_tracking_number_reads_shipping_document_when_tracking_is_not_in_order_detail(): void
    {
        $order = $this->seedLocalOrder('READY_TO_SHIP', 'pending');
        $order->update(['channel_package_ids' => ['PKG-1']]);

        $ready = $this->orderDetail('AWAITING_SHIPMENT', null, 'AWAITING_SHIPMENT');
        $detail = $this->orderDetail('AWAITING_COLLECTION', null, 'AWAITING_COLLECTION');

        Http::fake([
            self::BASE.'/fulfillment/202309/packages/PKG-1/shipping_documents*' => Http::response([
                'code' => 0,
                'data' => [
                    'tracking_number' => 'TTRK-DOCUMENT-1',
                    'shipping_provider_name' => 'TikTok Logistics',
                ],
            ], 200),
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response([
                'code' => 0,
                'data' => ['package_id' => 'PKG-1'],
            ], 200),
            self::BASE.'/order/202309/orders*' => Http::sequence()
                ->push($ready, 200)
                ->push($detail, 200),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            null,
            ['PKG-1'],
        );

        $this->assertTrue($result['shipped']);
        $this->assertSame('TTRK-DOCUMENT-1', $result['tracking_number']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/shipping_documents'));
    }

    public function test_fulfillment_snapshot_reads_awb_from_shipping_document_after_shipment(): void
    {
        $detail = $this->orderDetail('AWAITING_COLLECTION');
        $detail['data']['orders'][0]['packages'][0]['tracking_number'] = null;

        Http::fake([
            self::BASE.'/order/202309/orders*' => Http::response($detail, 200),
            self::BASE.'/fulfillment/202309/packages/PKG-1/shipping_documents*' => Http::response([
                'code' => 0,
                'data' => [
                    'tracking_number' => 'TTRK-DOCUMENT-1',
                    'shipping_provider_name' => 'TikTok Logistics',
                ],
            ], 200),
        ]);

        $snapshot = app(TikTokOrderService::class)->getOrderFulfillmentSnapshot(
            $this->shop,
            self::ORDER_ID,
        );

        $this->assertTrue($snapshot['order_found']);
        $this->assertSame('AWAITING_COLLECTION', $snapshot['status']);
        $this->assertSame('TTRK-DOCUMENT-1', $snapshot['tracking_number']);
        $this->assertSame('TTRK-DOCUMENT-1', $snapshot['packages'][0]['tracking_number']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/shipping_documents'));
    }

    public function test_fulfillment_snapshot_falls_back_to_package_detail_for_awb(): void
    {
        $detail = $this->orderDetail('AWAITING_COLLECTION');
        $detail['data']['orders'][0]['packages'][0]['tracking_number'] = null;

        Http::fake([
            self::BASE.'/order/202309/orders*' => Http::response($detail, 200),
            self::BASE.'/fulfillment/202309/packages/PKG-1/shipping_documents*' => Http::response([
                'code' => 0,
                'data' => [],
            ], 200),
            self::BASE.'/fulfillment/202309/packages/PKG-1*' => Http::response([
                'code' => 0,
                'data' => [
                    'tracking_number' => 'TTRK-PACKAGE-1',
                    'shipping_provider_name' => 'TikTok Logistics',
                ],
            ], 200),
        ]);

        $snapshot = app(TikTokOrderService::class)->getOrderFulfillmentSnapshot(
            $this->shop,
            self::ORDER_ID,
        );

        $this->assertSame('TTRK-PACKAGE-1', $snapshot['tracking_number']);
        $this->assertSame('TikTok Logistics', $snapshot['shipping_provider']);
        Http::assertSentCount(3);
    }

    public function test_request_tracking_does_not_post_when_preflight_cannot_be_read(): void
    {
        Http::fake([
            self::BASE.'/order/202309/orders*' => Http::response([
                'code' => 12001000,
                'message' => 'temporary error',
            ], 200),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            null,
            ['PKG-1'],
        );

        $this->assertTrue($result['deferred']);
        $this->assertFalse($result['shipped']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/ship'));
    }

    public function test_request_tracking_posts_only_the_package_still_awaiting_shipment(): void
    {
        $detail = $this->orderDetail('AWAITING_COLLECTION', 'TTRK-1', 'AWAITING_COLLECTION');
        $detail['data']['orders'][0]['packages'][] = [
            'id' => 'PKG-2',
            'tracking_number' => null,
            'shipping_provider_name' => 'TikTok Logistics',
            'status' => 'AWAITING_SHIPMENT',
        ];

        Http::fake([
            self::BASE.'/order/202309/orders*' => Http::response($detail, 200),
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response([
                'code' => 0,
                'data' => ['package_id' => 'PKG-2'],
            ], 200),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            null,
            ['PKG-1', 'PKG-2'],
        );

        $this->assertTrue($result['shipped']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/packages/ship')
            && $request['packages'] === [['id' => 'PKG-2']]);
    }

    public function test_request_tracking_keeps_successful_packages_when_tiktok_reports_batch_errors(): void
    {
        $snapshot = [
            'order_found' => true,
            'status' => 'AWAITING_SHIPMENT',
            'packages' => [
                ['id' => 'PKG-1', 'tracking_number' => null, 'status' => 'AWAITING_SHIPMENT'],
                ['id' => 'PKG-2', 'tracking_number' => null, 'status' => 'AWAITING_SHIPMENT'],
            ],
            'tracking_number' => null,
            'shipping_provider' => null,
            'has_pending_package' => true,
            'all_packages_shipped' => false,
        ];

        Http::fake([
            self::BASE.'/fulfillment/202309/packages/ship*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'request_id' => 'request-123',
                'data' => [
                    'errors' => [[
                        'code' => 10007014,
                        'message' => 'package in freeze status',
                        'detail' => ['package_id' => 'PKG-2'],
                    ]],
                ],
            ], 200),
            self::BASE.'/order/202309/orders*' => Http::response(
                $this->orderDetail('AWAITING_SHIPMENT', null, 'AWAITING_SHIPMENT'),
                200,
            ),
        ]);

        $result = app(TikTokOrderService::class)->requestTrackingNumber(
            'TT-700',
            self::ORDER_ID,
            ['preferred_method' => 'dropoff'],
            ['PKG-1', 'PKG-2'],
            $snapshot,
        );

        $this->assertFalse($result['shipped']);
        $this->assertSame(['PKG-2'], $result['failed_package_ids']);
        $this->assertTrue($result['packages'][0]['shipped']);
        $this->assertFalse($result['packages'][1]['shipped']);
        $this->assertSame(10007014, $result['packages'][1]['error_code']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/packages/ship')
            && $request['packages'] === [
                ['id' => 'PKG-1', 'handover_method' => 'DROP_OFF'],
                ['id' => 'PKG-2', 'handover_method' => 'DROP_OFF'],
            ]);
    }

    public function test_direct_tracking_reads_shipping_document_without_posting_ship(): void
    {
        $detail = $this->orderDetail('READY_TO_SHIP');
        $detail['data']['orders'][0]['packages'][0]['tracking_number'] = null;

        Http::fake([
            self::BASE.'/order/202309/orders*' => Http::response($detail, 200),
            self::BASE.'/fulfillment/202309/packages/PKG-1/shipping_documents*' => Http::response([
                'code' => 0,
                'data' => [
                    'tracking_number' => 'TTRK-DOCUMENT-1',
                    'doc_url' => 'https://cdn.example.test/tiktok-label.pdf',
                ],
            ], 200),
        ]);

        $result = app(TikTokOrderService::class)->resolveTrackingNumberDirect(
            $this->shop,
            self::ORDER_ID,
        );

        $this->assertSame('TTRK-DOCUMENT-1', $result['tracking_number']);
        $this->assertSame('TikTok Logistics', $result['shipping_provider']);
        $this->assertSame('READY_TO_SHIP', $result['channel_status']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/ship'));
    }

    public function test_decline_order_hits_cancellation_api_and_status_cancelled(): void
    {
        Http::fake([
            self::BASE.'/return_refund/202309/cancellations*' => Http::response(['code' => 0, 'data' => ['cancel_id' => 'C1']], 200),
            self::BASE.'/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
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
            self::BASE.'/return_refund/202309/cancellations/approve*' => Http::response(['code' => 0, 'data' => []], 200),
            self::BASE.'/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
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
            self::BASE.'/return_refund/202309/cancellations*' => Http::response(['code' => 0, 'data' => ['cancel_id' => 'C1']], 200),
            self::BASE.'/order/202309/orders*' => Http::response($this->orderDetail('CANCELLED'), 200),
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
