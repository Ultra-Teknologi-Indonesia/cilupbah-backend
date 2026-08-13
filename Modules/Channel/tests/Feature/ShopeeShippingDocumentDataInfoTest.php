<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

class ShopeeShippingDocumentDataInfoTest extends TestCase
{
    use RefreshDatabase;

    private function seedShop(): ChannelShop
    {
        $channel = Channel::firstOrCreate(['code' => 'shopee'], ['name' => 'Shopee']);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '778899',
            'shop_name' => 'Toko Uji',
            'access_token' => 'token-uji',
            'refresh_token' => 'refresh-uji',
            'is_active' => true,
        ]);
    }

    public function test_order_sn_dikirim_datar_bukan_di_dalam_order_list(): void
    {
        $this->seedShop();

        Http::fake([
            '*get_shipping_document_data_info*' => Http::response([
                'error' => '',
                'response' => ['shipping_document_info' => ['shipping_carrier' => 'SPX Standard']],
            ], 200),
        ]);

        app(ShopeeOrderService::class)->getShippingDocumentDataInfo(
            '778899',
            '2608138YNJT7Q1',
            null,
            'THERMAL_AIR_WAYBILL',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertArrayNotHasKey(
                'order_list',
                $body,
                'Shopee menolak bentuk bersarang untuk endpoint ini dengan '
                    .'"order_sn is a required field". Parameternya harus datar.',
            );

            $this->assertSame('2608138YNJT7Q1', $body['order_sn'] ?? null);
            $this->assertSame('THERMAL_AIR_WAYBILL', $body['shipping_document_type'] ?? null);

            return true;
        });
    }

    public function test_package_number_ikut_dikirim_datar_saat_diisi(): void
    {
        $this->seedShop();

        Http::fake([
            '*get_shipping_document_data_info*' => Http::response([
                'error' => '',
                'response' => [],
            ], 200),
        ]);

        app(ShopeeOrderService::class)->getShippingDocumentDataInfo(
            '778899',
            '2608138YNJT7Q1',
            'OFG240308887253905',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $this->assertSame('OFG240308887253905', $body['package_number'] ?? null);

            return true;
        });
    }
}
