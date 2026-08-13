<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationDispatcher;
use Tests\TestCase;

class ChannelRefreshFailureAlertTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function makeShop(array $override = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Toko Uji',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $override));
    }

    public function test_gagal_perpanjang_memicu_notifikasi(): void
    {
        $this->makeShop([
            'integration_status' => 'error',
            'last_error' => 'Koneksi ke Shopee terputus.',
        ]);

        $this->mock(NotificationDispatcher::class, function (MockInterface $mock) {
            $mock->shouldReceive('toPermission')
                ->once()
                ->withArgs(fn (string $permission, array $payload) => $permission === 'view-integrasi-channel'
                    && $payload['type'] === 'channel_refresh_failed'
                    && str_contains($payload['message'], 'Toko Uji'));
        });

        $this->artisan('channel:alert-reauth')->assertSuccessful();
    }

    public function test_toko_sehat_tidak_memicu_notifikasi(): void
    {
        $this->makeShop();

        $this->mock(NotificationDispatcher::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('toPermission');
        });

        $this->artisan('channel:alert-reauth')->assertSuccessful();
    }

    public function test_notifikasi_gagal_tidak_diulang_dalam_jendela_dedupe(): void
    {
        $shop = $this->makeShop([
            'integration_status' => 'error',
            'last_error' => 'Koneksi ke Shopee terputus.',
        ]);

        Notification::create([
            'user_id' => $this->createPrivilegedUser()->id,
            'title' => 'Perpanjangan koneksi Shopee gagal',
            'message' => 'alert sebelumnya',
            'type' => 'channel_refresh_failed',
            'data' => ['shop_id' => $shop->id],
        ]);

        $this->mock(NotificationDispatcher::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('toPermission');
        });

        $this->artisan('channel:alert-reauth')->assertSuccessful();
    }

    public function test_notifikasi_gagal_dikirim_lagi_setelah_jendela_dedupe_lewat(): void
    {
        $shop = $this->makeShop([
            'integration_status' => 'error',
            'last_error' => 'Koneksi ke Shopee terputus.',
        ]);

        $lama = Notification::create([
            'user_id' => $this->createPrivilegedUser()->id,
            'title' => 'Perpanjangan koneksi Shopee gagal',
            'message' => 'alert sebelumnya',
            'type' => 'channel_refresh_failed',
            'data' => ['shop_id' => $shop->id],
        ]);
        $lama->forceFill(['created_at' => now()->subHours(7)])->saveQuietly();

        $this->mock(NotificationDispatcher::class, function (MockInterface $mock) {
            $mock->shouldReceive('toPermission')->once();
        });

        $this->artisan('channel:alert-reauth')->assertSuccessful();
    }

    public function test_reauth_menang_atas_gagal_perpanjang(): void
    {
        $this->makeShop([
            'integration_status' => 'error',
            'refresh_token_expires_at' => now()->addDay(),
        ]);

        $this->mock(NotificationDispatcher::class, function (MockInterface $mock) {
            $mock->shouldReceive('toPermission')
                ->once()
                ->withArgs(fn (string $permission, array $payload) => $payload['type'] === 'channel_reauth_required');
        });

        $this->artisan('channel:alert-reauth')->assertSuccessful();
    }
}
