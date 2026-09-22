<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelOrderRefreshServiceTest extends TestCase
{
    #[DataProvider('channels')]
    public function test_refreshes_the_latest_order_from_the_requested_channel(string $channel, string $serviceProperty): void
    {
        $shopee = $this->createMock(ShopeeOrderService::class);
        $tiktok = $this->createMock(TikTokOrderService::class);
        $lazada = $this->createMock(LazadaOrderService::class);
        $woocommerce = $this->createMock(WooCommerceOrderService::class);
        $settings = $this->createMock(ChannelSyncSettingService::class);
        $settings->method('isPaused')->willReturn(false);

        ${$serviceProperty}->expects(self::once())
            ->method('pullOrderById')
            ->with('SHOP-1', 'ORDER-1')
            ->willReturn(1);

        $service = new ChannelOrderRefreshService($shopee, $tiktok, $lazada, $woocommerce, $settings);

        self::assertSame(1, $service->refresh($channel, 'SHOP-1', 'ORDER-1'));
    }

    public function test_does_not_call_a_channel_when_sync_is_paused(): void
    {
        $shopee = $this->createMock(ShopeeOrderService::class);
        $tiktok = $this->createMock(TikTokOrderService::class);
        $lazada = $this->createMock(LazadaOrderService::class);
        $woocommerce = $this->createMock(WooCommerceOrderService::class);
        $settings = $this->createMock(ChannelSyncSettingService::class);
        $settings->method('isPaused')->willReturn(true);

        $shopee->expects(self::never())->method('pullOrderById');
        $tiktok->expects(self::never())->method('pullOrderById');
        $lazada->expects(self::never())->method('pullOrderById');
        $woocommerce->expects(self::never())->method('pullOrderById');

        $service = new ChannelOrderRefreshService($shopee, $tiktok, $lazada, $woocommerce, $settings);

        self::assertSame(0, $service->refresh('tiktok', 'SHOP-1', 'ORDER-1'));
    }

    public static function channels(): array
    {
        return [
            'Shopee' => ['shopee', 'shopee'],
            'TikTok' => ['tiktok', 'tiktok'],
            'Lazada' => ['lazada', 'lazada'],
            'WooCommerce' => ['woocommerce', 'woocommerce'],
        ];
    }
}
