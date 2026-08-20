<?php

namespace Modules\Channel\Tests\Feature;

use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Tests\TestCase;

class WebhookQueueTieringTest extends TestCase
{
    public function test_shopee_resolves_tiered_queues_correctly(): void
    {
        // Order status / cancellation
        $this->assertSame('shopee-orders', ProcessShopeeWebhook::resolveQueueName(['code' => 3]));

        // Tracking / Fulfillment / Logistics
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 4]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 37]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 15]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 30]));

        // Aftersales / Return
        $this->assertSame('shopee-aftersales', ProcessShopeeWebhook::resolveQueueName(['code' => 29]));

        // Catalog / Price / Stock
        $this->assertSame('shopee-catalog', ProcessShopeeWebhook::resolveQueueName(['code' => 22]));
        $this->assertSame('shopee-catalog', ProcessShopeeWebhook::resolveQueueName(['code' => 8]));

        // Default fallback
        $this->assertSame('shopee-webhooks', ProcessShopeeWebhook::resolveQueueName(['code' => 999]));
    }

    public function test_tiktok_resolves_tiered_queues_correctly(): void
    {
        // Orders
        $this->assertSame('tiktok-orders', ProcessTikTokWebhook::resolveQueueName(['type' => 1]));
        $this->assertSame('tiktok-orders', ProcessTikTokWebhook::resolveQueueName(['type' => 11]));

        // Packages / Fulfillment
        $this->assertSame('tiktok-packages', ProcessTikTokWebhook::resolveQueueName(['type' => 4]));

        // Aftersales / Return
        $this->assertSame('tiktok-aftersales', ProcessTikTokWebhook::resolveQueueName(['type' => 2]));
        $this->assertSame('tiktok-aftersales', ProcessTikTokWebhook::resolveQueueName(['type' => 64]));

        // Catalog
        $this->assertSame('tiktok-catalog', ProcessTikTokWebhook::resolveQueueName(['type' => 5]));
        $this->assertSame('tiktok-catalog', ProcessTikTokWebhook::resolveQueueName(['type' => 50]));

        // Fallback
        $this->assertSame('tiktok-webhooks', ProcessTikTokWebhook::resolveQueueName(['type' => 999]));
    }

    public function test_lazada_resolves_tiered_queues_correctly(): void
    {
        // Orders
        $this->assertSame('lazada-orders', ProcessLazadaWebhook::resolveQueueName(['message_type' => 0]));

        // Fulfillment
        $this->assertSame('lazada-fulfillment', ProcessLazadaWebhook::resolveQueueName(['message_type' => 14]));

        // Aftersales
        $this->assertSame('lazada-aftersales', ProcessLazadaWebhook::resolveQueueName(['message_type' => 10]));

        // Catalog
        $this->assertSame('lazada-catalog', ProcessLazadaWebhook::resolveQueueName(['message_type' => 1]));
        $this->assertSame('lazada-catalog', ProcessLazadaWebhook::resolveQueueName(['message_type' => 3]));

        // Fallback
        $this->assertSame('lazada-webhooks', ProcessLazadaWebhook::resolveQueueName(['message_type' => 999]));
    }
}
