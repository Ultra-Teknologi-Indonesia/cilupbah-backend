<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

final class ShopeeMassAwbApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee Mass Test',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'handover_method' => 'pickup',
        ]);
    }

    public function test_resolves_packages_for_multiple_orders_without_positional_matching(): void
    {
        Http::fake([
            '*get_order_detail*' => Http::response([
                'error' => '',
                'response' => ['order_list' => [
                    [
                        'order_sn' => 'ORDER-B',
                        'package_list' => [[
                            'package_number' => 'PKG-B',
                            'logistics_channel_id' => 8002,
                            'product_location_id' => 'LOC-B',
                        ]],
                    ],
                    [
                        'order_sn' => 'ORDER-A',
                        'package_list' => [[
                            'package_number' => 'PKG-A',
                            'logistics_channel_id' => 8001,
                            'product_location_id' => 'LOC-A',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $result = app(ShopeeOrderService::class)
            ->resolveMassPackages('778899', ['ORDER-A', 'ORDER-B']);

        $this->assertSame('PKG-A', $result['ORDER-A'][0]['package_number']);
        $this->assertSame(8001, $result['ORDER-A'][0]['logistics_channel_id']);
        $this->assertSame('LOC-A', $result['ORDER-A'][0]['product_location_id']);
        $this->assertSame('PKG-B', $result['ORDER-B'][0]['package_number']);
    }

    public function test_reads_mass_tracking_numbers_and_preserves_partial_failures(): void
    {
        Http::fake([
            '*get_mass_tracking_number*' => Http::response([
                'error' => '',
                'response' => [
                    'success_list' => [[
                        'package_number' => 'PKG-A',
                        'tracking_number' => 'SPX-A',
                        'pickup_code' => '7788',
                        'first_mile_tracking_number' => '',
                    ]],
                    'fail_list' => [[
                        'package_number' => 'PKG-B',
                        'fail_reason' => 'Tracking number is not ready',
                    ]],
                ],
            ]),
        ]);

        $result = app(ShopeeOrderService::class)
            ->getMassTrackingNumbers('778899', ['PKG-A', 'PKG-B']);

        $this->assertSame('SPX-A', $result['results']['PKG-A']['tracking_number']);
        $this->assertSame('7788', $result['results']['PKG-A']['pickup_code']);
        $this->assertFalse($result['results']['PKG-B']['succeeded']);
        $this->assertSame('Tracking number is not ready', $result['results']['PKG-B']['error']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/api/v2/logistics/get_mass_tracking_number')) {
                return false;
            }

            $this->assertSame([
                ['package_number' => 'PKG-A'],
                ['package_number' => 'PKG-B'],
            ], $request->data()['package_list'] ?? null);
            $this->assertSame(
                'first_mile_tracking_number',
                $request->data()['response_optional_fields'] ?? null,
            );

            return true;
        });
    }

    public function test_mass_ship_uses_package_list_and_returns_per_package_result(): void
    {
        Http::fake([
            '*get_mass_shipping_parameter*' => Http::response([
                'error' => '',
                'response' => [
                    'info_needed' => ['pickup' => ['address_id', 'pickup_time_id']],
                    'pickup' => ['address_list' => [[
                        'address_id' => 200000015,
                        'time_slot_list' => [['pickup_time_id' => '1737104400_5']],
                    ]]],
                ],
            ]),
            '*mass_ship_order*' => Http::response([
                'error' => '',
                'response' => [
                    'success_list' => [['package_number' => 'PKG-A']],
                    'fail_list' => [[
                        'package_number' => 'PKG-B',
                        'fail_reason' => 'Package is not under the specified fulfilment channel',
                    ]],
                ],
            ]),
        ]);

        $result = app(ShopeeOrderService::class)
            ->massShipPackages('778899', ['PKG-A', 'PKG-B'], [
                'logistics_channel_id' => '8001',
                'product_location_id' => 'LOC-A',
            ]);

        $this->assertTrue($result['results']['PKG-A']['shipped']);
        $this->assertFalse($result['results']['PKG-B']['shipped']);
        $this->assertSame(
            'Package is not under the specified fulfilment channel',
            $result['results']['PKG-B']['error'],
        );

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/api/v2/logistics/get_mass_shipping_parameter')) {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertSame([
                ['package_number' => 'PKG-A'],
                ['package_number' => 'PKG-B'],
            ], $request->data()['package_list'] ?? null);
            $this->assertSame(8001, $request->data()['logistics_channel_id'] ?? null);
            $this->assertSame('LOC-A', $request->data()['product_location_id'] ?? null);

            return true;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/api/v2/logistics/mass_ship_order')) {
                return false;
            }

            $this->assertSame([
                ['package_number' => 'PKG-A'],
                ['package_number' => 'PKG-B'],
            ], $request->data()['package_list'] ?? null);
            $this->assertSame(8001, $request->data()['logistics_channel_id'] ?? null);
            $this->assertSame(200000015, data_get($request->data(), 'pickup.address_id'));

            return true;
        });
    }

    public function test_mass_document_endpoints_chunk_at_fifty_and_preserve_package_identity(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $rows = $request->data()['order_list'] ?? [];

            if ($path === '/api/v2/logistics/create_shipping_document') {
                return Http::response([
                    'error' => '',
                    'response' => [
                        'result_list' => array_map(
                            static fn (array $row): array => [
                                'order_sn' => $row['order_sn'],
                                'package_number' => $row['package_number'] ?? null,
                            ],
                            $rows,
                        ),
                    ],
                ]);
            }

            if ($path === '/api/v2/logistics/get_shipping_document_result') {
                return Http::response([
                    'error' => '',
                    'response' => [
                        'result_list' => array_map(
                            static fn (array $row): array => [
                                'order_sn' => $row['order_sn'],
                                'package_number' => $row['package_number'] ?? null,
                                'status' => 'READY',
                            ],
                            $rows,
                        ),
                    ],
                ]);
            }

            if ($path === '/api/v2/logistics/download_shipping_document') {
                return Http::response('%PDF-1.4 BULK LABEL', 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            return Http::response([], 404);
        });

        $orders = array_map(
            static fn (int $index): array => [
                'order_sn' => 'ORDER-'.$index,
                'package_number' => 'PKG-'.$index,
                'tracking_number' => 'AWB-'.$index,
                'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
            ],
            range(1, 51),
        );

        $service = app(ShopeeOrderService::class);
        $created = $service->createShippingDocumentsMass('778899', $orders);
        $statuses = $service->getShippingDocumentResultsMass('778899', $orders);
        $downloaded = $service->downloadShippingDocumentsMass('778899', $orders, 'THERMAL_AIR_WAYBILL');

        $this->assertCount(51, $created['results']);
        $this->assertTrue($created['results']['ORDER-51|PKG-51']['accepted']);
        $this->assertTrue($statuses['results']['ORDER-1|PKG-1']['ready']);
        $this->assertTrue($statuses['results']['ORDER-51|PKG-51']['ready']);
        $this->assertCount(2, $downloaded['batches']);
        $this->assertSame('%PDF-1.4 BULK LABEL', $downloaded['batches'][0]['content']);

        $requests = Http::recorded()->map(static fn (array $record): Request => $record[0]);
        $createRequests = $requests->filter(
            static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_shipping_document'),
        );
        $resultRequests = $requests->filter(
            static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/get_shipping_document_result'),
        );
        $downloadRequests = $requests->filter(
            static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/download_shipping_document'),
        );

        $this->assertCount(2, $createRequests);
        $this->assertCount(2, $resultRequests);
        $this->assertCount(2, $downloadRequests);
        $this->assertSame(50, count($createRequests->first()->data()['order_list']));
        $this->assertSame(1, count($createRequests->last()->data()['order_list']));
        $this->assertSame(50, count($resultRequests->first()->data()['order_list']));
        $this->assertSame(50, count($downloadRequests->first()->data()['order_list']));
    }

    public function test_mass_tracking_chunks_requests_above_shopee_limit(): void
    {
        Http::fake(function (Request $request) {
            $packages = $request->data()['package_list'] ?? [];

            return Http::response([
                'error' => '',
                'response' => [
                    'success_list' => array_map(
                        static fn (array $row): array => [
                            'package_number' => $row['package_number'],
                            'tracking_number' => 'AWB-'.$row['package_number'],
                        ],
                        $packages,
                    ),
                    'fail_list' => [],
                ],
            ]);
        });

        $packages = array_map(static fn (int $index): string => 'PKG-'.$index, range(1, 51));
        $result = app(ShopeeOrderService::class)->getMassTrackingNumbers('778899', $packages);

        $this->assertCount(51, $result['results']);
        $this->assertSame('AWB-PKG-51', $result['results']['PKG-51']['tracking_number']);

        $requests = Http::recorded()->map(static fn (array $record): Request => $record[0]);
        $this->assertCount(2, $requests);
        $this->assertSame(50, count($requests[0]->data()['package_list']));
        $this->assertSame(1, count($requests[1]->data()['package_list']));
    }
}
