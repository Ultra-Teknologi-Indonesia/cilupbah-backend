<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class ShadowPullWindowTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => true,
            'shadow_started_at' => now()->subDays(3),
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

    public function test_manual_backfill_cannot_reach_before_cutoff(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-shadow-orders', ['--from' => now()->subDays(60)->toDateString()])
            ->assertSuccessful();

        $cutoff = $this->shop->shadow_started_at->timestamp;

        Http::assertSent(function ($request) use ($cutoff) {
            if (! str_contains($request->url(), '/order/get_order_list')) {
                return false;
            }

            return (int) $request['time_from'] >= $cutoff;
        });
    }

    public function test_cursor_advances_only_after_a_successful_run(): void
    {
        $this->fakeEmptyOrderList();

        $this->assertNull($this->shop->shadow_last_pulled_at);

        $this->artisan('channel:pull-shadow-orders')->assertSuccessful();

        $this->assertNotNull($this->shop->fresh()->shadow_last_pulled_at);
    }

    public function test_dry_run_does_not_move_the_cursor(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-shadow-orders', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($this->shop->fresh()->shadow_last_pulled_at);
    }
}
