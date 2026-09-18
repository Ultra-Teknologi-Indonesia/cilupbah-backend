<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Services\TikTokClient;
use Tests\TestCase;

class ChannelClientCredentialsTest extends TestCase
{
    public function test_clients_can_be_instantiated_without_configured_credentials(): void
    {
        config([
            'services.shopee.partner_id' => '',
            'services.shopee.partner_key' => '',
            'services.tiktok.app_key' => '',
            'services.tiktok.app_secret' => '',
            'services.lazada.app_key' => '',
            'services.lazada.app_secret' => '',
        ]);

        $shopee = new ShopeeClient();
        $tiktok = new TikTokClient();
        $lazada = new LazadaClient();

        $this->assertInstanceOf(ShopeeClient::class, $shopee);
        $this->assertInstanceOf(TikTokClient::class, $tiktok);
        $this->assertInstanceOf(LazadaClient::class, $lazada);
    }

    public function test_shopee_client_throws_on_request_when_credentials_unconfigured(): void
    {
        config([
            'services.shopee.partner_id' => '',
            'services.shopee.partner_key' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kredensial Shopee belum dikonfigurasi');

        $shopee = new ShopeeClient();
        $shopee->getAuthUrl('https://example.com/callback');
    }

    public function test_tiktok_client_throws_on_request_when_credentials_unconfigured(): void
    {
        config([
            'services.tiktok.app_key' => '',
            'services.tiktok.app_secret' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TikTok credentials are not configured');

        $tiktok = new TikTokClient();
        $tiktok->generateSignature('/test', []);
    }

    public function test_lazada_client_throws_on_request_when_credentials_unconfigured(): void
    {
        config([
            'services.lazada.app_key' => '',
            'services.lazada.app_secret' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kredensial Lazada belum dikonfigurasi');

        $lazada = new LazadaClient();
        $lazada->getAuthUrl('https://example.com/callback');
    }
}
