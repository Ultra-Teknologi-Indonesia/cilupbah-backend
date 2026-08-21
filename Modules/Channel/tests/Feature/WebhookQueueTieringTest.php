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

        $this->assertSame('shopee-orders', ProcessShopeeWebhook::resolveQueueName(['code' => 3]));

        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 4]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 37]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 15]));
        $this->assertSame('shopee-tracking', ProcessShopeeWebhook::resolveQueueName(['code' => 30]));

        $this->assertSame('shopee-aftersales', ProcessShopeeWebhook::resolveQueueName(['code' => 29]));

        $this->assertSame('shopee-catalog', ProcessShopeeWebhook::resolveQueueName(['code' => 22]));
        $this->assertSame('shopee-catalog', ProcessShopeeWebhook::resolveQueueName(['code' => 8]));

        $this->assertSame('shopee-webhooks', ProcessShopeeWebhook::resolveQueueName(['code' => 999]));
    }

    public function test_tiktok_resolves_tiered_queues_correctly(): void
    {

        $this->assertSame('tiktok-orders', ProcessTikTokWebhook::resolveQueueName(['type' => 1]));
        $this->assertSame('tiktok-orders', ProcessTikTokWebhook::resolveQueueName(['type' => 11]));

        $this->assertSame('tiktok-packages', ProcessTikTokWebhook::resolveQueueName(['type' => 4]));

        $this->assertSame('tiktok-aftersales', ProcessTikTokWebhook::resolveQueueName(['type' => 2]));
        $this->assertSame('tiktok-aftersales', ProcessTikTokWebhook::resolveQueueName(['type' => 64]));

        $this->assertSame('tiktok-catalog', ProcessTikTokWebhook::resolveQueueName(['type' => 5]));
        $this->assertSame('tiktok-catalog', ProcessTikTokWebhook::resolveQueueName(['type' => 50]));

        $this->assertSame('tiktok-webhooks', ProcessTikTokWebhook::resolveQueueName(['type' => 999]));
    }

    public function test_lazada_resolves_tiered_queues_correctly(): void
    {

        $this->assertSame('lazada-orders', ProcessLazadaWebhook::resolveQueueName(['message_type' => 0]));

        $this->assertSame('lazada-fulfillment', ProcessLazadaWebhook::resolveQueueName(['message_type' => 14]));

        $this->assertSame('lazada-aftersales', ProcessLazadaWebhook::resolveQueueName(['message_type' => 10]));

        $this->assertSame('lazada-catalog', ProcessLazadaWebhook::resolveQueueName(['message_type' => 1]));
        $this->assertSame('lazada-catalog', ProcessLazadaWebhook::resolveQueueName(['message_type' => 3]));

        $this->assertSame('lazada-webhooks', ProcessLazadaWebhook::resolveQueueName(['message_type' => 999]));
    }
}
