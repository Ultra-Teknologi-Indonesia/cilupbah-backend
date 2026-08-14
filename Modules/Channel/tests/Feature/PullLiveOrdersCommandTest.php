<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class PullLiveOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $liveShop;
    private ChannelShop $shadowShop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->liveShop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '112233',
            'shop_name' => 'Live Shopee Shop',
            'access_token' => 'live-token',
            'refresh_token' => 'live-refresh',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => false,
            'stock_push_enabled' => false,
        ]);

        $this->shadowShop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '445566',
            'shop_name' => 'Shadow Shopee Shop',
            'access_token' => 'shadow-token',
            'refresh_token' => 'shadow-refresh',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => true,
            'stock_push_enabled' => false,
        ]);
    }

    private function fakeEmptyOrderList(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::response([
                'response' => ['order_list' => [], 'more' => false, 'next_cursor' => ''],
            ], 200),
        ]);
    }

    public function test_pull_orders_targets_live_shops_by_default(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-orders', ['--shop' => '112233'])
            ->assertSuccessful()
            ->expectsOutputToContain('Live Shopee Shop');
    }

    public function test_pull_orders_skips_shadow_shops_unless_flagged(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-orders', ['--shop' => '445566'])
            ->assertSuccessful()
            ->expectsOutputToContain('Tidak ada toko aktif');

        $this->artisan('channel:pull-orders', ['--shop' => '445566', '--include-shadow' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Shadow Shopee Shop');
    }
}
