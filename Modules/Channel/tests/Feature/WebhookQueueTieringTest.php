<?php

namespace Modules\Channel\Tests\Feature;

use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
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

    public function test_webhook_jobs_have_stable_unique_ids(): void
    {
        $shopee = ['shop_id' => 'shop-1', 'code' => 3, 'timestamp' => 100, 'data' => ['ordersn' => 'order-1']];
        $tiktok = ['tts_notification_id' => 'notification-1', 'shop_id' => 'shop-1', 'type' => 1];
        $lazada = ['seller_id' => 'seller-1', 'message_type' => 0, 'timestamp' => 100, 'data' => ['trade_order_id' => 'order-1']];
        $woocommerce = ['id' => 1, 'date_modified' => '2026-09-10T00:00:00Z'];

        $this->assertSame(ProcessShopeeWebhook::idempotencyKey($shopee), (new ProcessShopeeWebhook($shopee))->uniqueId());
        $this->assertSame(ProcessTikTokWebhook::idempotencyKey($tiktok), (new ProcessTikTokWebhook($tiktok))->uniqueId());
        $this->assertSame(ProcessLazadaWebhook::idempotencyKey($lazada), (new ProcessLazadaWebhook($lazada))->uniqueId());
        $this->assertSame(ProcessWooCommerceWebhook::idempotencyKey('shop-1', 'order.updated', $woocommerce), (new ProcessWooCommerceWebhook('shop-1', 'order.updated', '1', $woocommerce))->uniqueId());
    }
}
