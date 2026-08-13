<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelReconciliationService;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\AdminAlertJob;
use Tests\TestCase;

class OrderIngestionSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function makeShop(array $override = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Toko Uji',
            'access_token' => 'access-lama',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addMinutes(30),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $override));
    }

    public function test_pemanggil_dengan_token_basi_tidak_ikut_memutar_token(): void
    {
        $shop = $this->makeShop([
            'access_token' => 'access-baru',
            'token_expires_at' => now()->addHours(4),
        ]);

        Http::fake();

        $service = app(ShopeeAuthService::class);

        $hasil = \Closure::bind(
            fn () => $this->lockedTokenRefresh(
                $shop->id,
                'shopee',
                'access-lama',
                fn () => throw new \RuntimeException('refresh tidak boleh dipanggil ulang'),
            ),
            $service,
            ShopeeAuthService::class,
        )();

        Http::assertNothingSent();

        $this->assertSame($shop->shop_id, $hasil['shop_id']);
        $this->assertSame('access-baru', $shop->fresh()->access_token);
    }

    public function test_refresh_manual_tetap_jalan_saat_token_belum_berputar(): void
    {
        $shop = $this->makeShop();

        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/access_token/get*' => Http::response([
                'access_token' => 'access-baru',
                'refresh_token' => 'refresh-baru',
                'expire_in' => 14400,
            ], 200),
        ]);

        app(ShopeeAuthService::class)->refreshStoreToken($shop->id);

        Http::assertSentCount(1);
        $this->assertSame('access-baru', $shop->fresh()->access_token);
    }

    public function test_order_hilang_ditarik_ulang_oleh_reconcile(): void
    {
        Queue::fake();
        $this->makeShop();

        $this->mock(ChannelReconciliationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('auditOrders')->once()->andReturn([[
                'channel' => 'shopee',
                'shop_id' => '778899',
                'channel_count' => 3,
                'local_count' => 1,
                'missing_count' => 2,
                'missing' => ['ORD-1', 'ORD-2'],
                'missing_sample' => ['ORD-1', 'ORD-2'],
            ]]);

            $mock->shouldReceive('pullMissingOrders')
                ->once()
                ->withArgs(fn (string $code, string $shopId, array $ids, int $limit) => $code === 'shopee'
                    && $ids === ['ORD-1', 'ORD-2'])
                ->andReturn(['pulled' => 2, 'failed' => 0, 'failed_ids' => []]);

            $mock->shouldReceive('discoverShopeeReturns')->once()->andReturn([]);
        });

        $this->artisan('channel:reconcile-orders')->assertSuccessful();

        Queue::assertNotPushed(AdminAlertJob::class);
    }

    public function test_order_yang_tetap_gagal_memicu_alert_admin(): void
    {
        Queue::fake();
        $this->makeShop();

        $this->mock(ChannelReconciliationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('auditOrders')->once()->andReturn([[
                'channel' => 'shopee',
                'shop_id' => '778899',
                'channel_count' => 3,
                'local_count' => 1,
                'missing_count' => 2,
                'missing' => ['ORD-1', 'ORD-2'],
                'missing_sample' => ['ORD-1', 'ORD-2'],
            ]]);

            $mock->shouldReceive('pullMissingOrders')
                ->once()
                ->andReturn(['pulled' => 1, 'failed' => 1, 'failed_ids' => ['ORD-2']]);

            $mock->shouldReceive('discoverShopeeReturns')->once()->andReturn([]);
        });

        $this->artisan('channel:reconcile-orders')->assertSuccessful();

        Queue::assertPushed(AdminAlertJob::class, fn (AdminAlertJob $job) => in_array('ORD-2', $job->context['order_ids'], true));
    }

    public function test_backfill_menarik_order_yang_hilang(): void
    {
        $this->makeShop();

        $this->mock(ShopeeOrderService::class, function (MockInterface $mock) {
            $mock->shouldReceive('pullOrderById')->with('778899', 'ORD-1')->once()->andReturn(1);
            $mock->shouldReceive('pullOrderById')->with('778899', 'ORD-2')->once()->andReturn(0);
        });

        $hasil = app(ChannelReconciliationService::class)
            ->pullMissingOrders('shopee', '778899', ['ORD-1', 'ORD-2']);

        $this->assertSame(1, $hasil['pulled']);
        $this->assertSame(1, $hasil['failed']);
        $this->assertSame(['ORD-2'], $hasil['failed_ids']);
    }
}
