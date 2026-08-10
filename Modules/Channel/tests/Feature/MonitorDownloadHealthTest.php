<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Tests\TestCase;

class MonitorDownloadHealthTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): ChannelShop
    {
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHP-1',
            'shop_name' => 'Toko',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
    }

    public function test_alerts_when_failure_rate_exceeds_threshold(): void
    {
        Log::spy();
        $shop = $this->shop();

        DownloadTransaction::create([
            'channel_shop_id' => $shop->id,
            'state' => DownloadTransaction::STATE_DONE,
            'all_product' => 100,
            'total_downloaded' => 70,
            'total_failed' => 30,
            'progress_percent' => 100,
        ]);

        $this->artisan('channel:monitor-download-health')->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'failure-rate'))
            ->atLeast()->once();
    }

    public function test_no_alert_when_healthy(): void
    {
        Log::spy();
        $shop = $this->shop();

        DownloadTransaction::create([
            'channel_shop_id' => $shop->id,
            'state' => DownloadTransaction::STATE_DONE,
            'all_product' => 100,
            'total_downloaded' => 100,
            'total_failed' => 1,
            'progress_percent' => 100,
        ]);

        $this->artisan('channel:monitor-download-health')
            ->expectsOutputToContain('Sehat')
            ->assertSuccessful();

        Log::shouldNotHaveReceived('warning');
    }
}
