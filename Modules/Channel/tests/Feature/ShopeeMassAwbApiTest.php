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
        $this->assertSame('8001', $result['ORDER-A'][0]['logistics_channel_id']);
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
            $this->assertSame('8001', $request->data()['logistics_channel_id'] ?? null);
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
            $this->assertSame(200000015, data_get($request->data(), 'pickup.address_id'));

            return true;
        });
    }
}
