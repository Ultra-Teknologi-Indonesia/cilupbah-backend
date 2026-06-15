<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class LazadaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
        ]);

        $lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $lazada->id,
            'shop_id' => 'LZ-100',
            'shop_name' => 'Toko Lazada',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    private function orderPayload(array $override = []): array
    {
        return array_merge([
            'seller_id' => 'LZ-100',
            'message_type' => 0,
            'timestamp' => 1760000000,
            'site' => 'lazada_id',
            'data' => ['trade_order_id' => '900123', 'order_status' => 'pending'],
        ], $override);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', 'test_key' . $body, 'test_secret');
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/lazada/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature ?? $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_valid_signature_enqueues_job(): void
    {
        Queue::fake();

        $this->postWebhook($this->orderPayload())->assertStatus(200);

        Queue::assertPushed(ProcessLazadaWebhook::class, fn ($job) => ($job->payload['data']['trade_order_id'] ?? null) === '900123');
    }

    public function test_invalid_signature_returns_401_without_job(): void
    {
        Queue::fake();

        $this->postWebhook($this->orderPayload(), 'bukan-signature')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_event_not_enqueued_twice(): void
    {
        Queue::fake();

        $this->postWebhook($this->orderPayload())->assertStatus(200);
        $this->postWebhook($this->orderPayload())->assertStatus(200)->assertJsonPath('data.duplicate', true);

        Queue::assertPushed(ProcessLazadaWebhook::class, 1);
    }

    public function test_malformed_body_returns_200_without_job_or_500(): void
    {
        Queue::fake();

        $body = 'bukan-json';
        $this->call('POST', '/api/v1/lazada/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_order_event_pulls_single_order_into_sales_order(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/order/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'order_id' => 900123,
                    'statuses' => ['pending'],
                    'price' => '120000.00',
                    'created_at' => '2026-06-12 08:00:00 +0700',
                    'customer_first_name' => 'Sari',
                ],
            ], 200),
            'api.lazada.co.id/rest/orders/items/get*' => Http::response([
                'code' => '0',
                'data' => [[
                    'order_id' => 900123,
                    'order_items' => [['order_item_id' => 11, 'name' => 'Tas', 'sku' => 'SKU-TAS', 'item_price' => 120000, 'paid_price' => 120000]],
                ]],
            ], 200),
        ]);

        (new ProcessLazadaWebhook($this->orderPayload()))->handle(app(\Modules\Channel\Services\LazadaOrderService::class));

        $order = SalesOrder::where('salesorder_no', '900123')->first();
        $this->assertNotNull($order);
        $this->assertEquals('lazada', $order->source);
    }

    public function test_product_qc_rejected_marks_mapping_failed(): void
    {
        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'P', 'status' => 'master', 'is_active' => true]);
        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555100',
            'sync_status' => 'synced',
        ]);

        $payload = $this->orderPayload([
            'message_type' => 1,
            'data' => ['item_id' => '555100', 'qc_status' => 'rejected', 'reasons' => 'Gambar buram'],
        ]);

        (new ProcessLazadaWebhook($payload))->handle(app(\Modules\Channel\Services\LazadaOrderService::class));

        $mapping->refresh();
        $this->assertEquals('rejected', $mapping->sync_status);
        $this->assertStringContainsString('Gambar buram', $mapping->error_message);
    }

    public function test_unknown_message_type_handled_gracefully(): void
    {
        $payload = $this->orderPayload(['message_type' => 99, 'data' => []]);

        (new ProcessLazadaWebhook($payload))->handle(app(\Modules\Channel\Services\LazadaOrderService::class));

        $this->assertTrue(true); 
    }

    public function test_horizon_default_supervisor_serves_webhooks_queue(): void
    {
        $queues = config('horizon.defaults.supervisor-default.queue');

        $this->assertContains('webhooks', $queues, "Queue 'webhooks' (outbound webhook internal) harus dilayani supervisor-default.");
    }
}
