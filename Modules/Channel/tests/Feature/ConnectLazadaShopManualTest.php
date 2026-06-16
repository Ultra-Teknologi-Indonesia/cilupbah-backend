<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class ConnectLazadaShopManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_connects_shop_with_token(): void
    {
        Channel::create(['code' => 'lazada', 'name' => 'Lazada']);

        $this->artisan('lazada:connect-manual', [
            'shop_id' => 'LZTEST1',
            'access_token' => 'tok-123',
            '--shop-name' => 'Toko Test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('channel_shops', [
            'shop_id' => 'LZTEST1',
            'shop_name' => 'Toko Test',
            'is_active' => true,
            'disconnected_at' => null,
        ]);

        $shop = ChannelShop::where('shop_id', 'LZTEST1')->first();
        $this->assertEquals('tok-123', $shop->access_token);
        $this->assertNotNull($shop->token_expires_at);
    }

    public function test_updates_existing_shop_token(): void
    {
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'LZTEST1', 'shop_name' => 'Lama',
            'access_token' => 'tok-lama', 'is_active' => true,
        ]);

        $this->artisan('lazada:connect-manual', [
            'shop_id' => 'LZTEST1',
            'access_token' => 'tok-baru',
        ])->assertSuccessful();

        $this->assertEquals(1, ChannelShop::where('shop_id', 'LZTEST1')->count());
        $this->assertEquals('tok-baru', ChannelShop::where('shop_id', 'LZTEST1')->first()->access_token);
    }

    public function test_fails_when_channel_not_seeded(): void
    {
        $this->artisan('lazada:connect-manual', [
            'shop_id' => 'LZTEST1',
            'access_token' => 'tok',
        ])->assertFailed();
    }
}
