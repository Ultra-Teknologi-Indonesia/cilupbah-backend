<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Helpers\ShopeeSignature;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class ShopeeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://localhost/api/v1/shopee/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.push_partner_key' => '',
            'services.shopee.push_url' => '',
            'services.shopee.redirect_uri' => '',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    private function orderPayload(array $override = []): array
    {
        return array_merge([
            'shop_id' => 778899,
            'code' => 3,
            'timestamp' => 1760000000,
            'data' => ['ordersn' => '2606SHOPEE01', 'status' => 'READY_TO_SHIP'],
        ], $override);
    }

    private function sign(string $body): string
    {
        return ShopeeSignature::pushSign(self::URL, $body, 'test_partner_key');
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/shopee/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature ?? $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_valid_signature_enqueues_job(): void
    {
        Queue::fake();

        $this->postWebhook($this->orderPayload())
            ->assertStatus(200)
            ->assertSee('', false);

        Queue::assertPushed(ProcessShopeeWebhook::class, fn ($job) => ($job->payload['data']['ordersn'] ?? null) === '2606SHOPEE01');
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
        $this->postWebhook($this->orderPayload())->assertStatus(200);

        Queue::assertPushed(ProcessShopeeWebhook::class, 1);
    }

    public function test_malformed_body_returns_200_without_job_or_500(): void
    {
        Queue::fake();

        $body = 'bukan-json';
        $this->call('POST', '/api/v1/shopee/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_deauthorized_push_marks_shop_inactive(): void
    {
        (new ProcessShopeeWebhook($this->orderPayload([
            'code' => 2,
            'data' => [],
        ])))->handle(app(\Modules\Channel\Services\ShopeeOrderService::class), app(\Modules\Channel\Services\ChannelDownloadService::class));

        $this->assertDatabaseHas('channel_shops', [
            'shop_id' => '778899',
            'is_active' => false,
            'integration_status' => 'error',
        ]);
    }

    public function test_unknown_code_handled_gracefully(): void
    {
        (new ProcessShopeeWebhook($this->orderPayload(['code' => 99, 'data' => []])))->handle(app(\Modules\Channel\Services\ShopeeOrderService::class), app(\Modules\Channel\Services\ChannelDownloadService::class));

        $this->assertTrue(true);
    }

    public function test_item_update_pulls_full_product_content(): void
    {
        $shopUuid = ChannelShop::where('shop_id', '778899')->value('id');
        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'P', 'status' => 'master', 'is_active' => true]);
        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shopUuid,
            'external_product_id' => 'SP-1',
            'sync_status' => 'synced',
        ]);

        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('shopee', '778899', 'SP-1');

        (new ProcessShopeeWebhook($this->orderPayload([
            'code' => 22,
            'data' => ['item_id' => 'SP-1', 'status' => 'normal'],
        ])))->handle(app(ShopeeOrderService::class), $download);

        $this->assertDatabaseHas('product_channel_mappings', [
            'external_product_id' => 'SP-1',
            'sync_status' => 'synced',
        ]);
    }

    public function test_return_update_push_dispatches_channel_return_job(): void
    {
        Queue::fake();

        $orderService = Mockery::mock(ShopeeOrderService::class);
        $orderService->shouldReceive('pullOrderById')->once()->with('778899', '2606SHOPEE01');

        (new ProcessShopeeWebhook($this->orderPayload([
            'code' => 29,
            'data' => ['ordersn' => '2606SHOPEE01', 'return_sn' => 'RET-1', 'reason' => 'Barang rusak'],
        ])))->handle($orderService, app(ChannelDownloadService::class));

        Queue::assertPushed(\Modules\Sales\Jobs\ProcessChannelReturnJob::class, fn ($job) => ($job->payload['source'] ?? null) === 'shopee'
            && ($job->payload['channel_order_id'] ?? null) === '2606SHOPEE01'
            && ($job->payload['channel_return_id'] ?? null) === 'RET-1');
    }

    public function test_configured_push_url_used_for_signature(): void
    {
        Queue::fake();

        $customUrl = 'https://staging.example.com/api/v1/shopee/callback';
        config(['services.shopee.push_url' => $customUrl]);

        $payload = $this->orderPayload();
        $body = json_encode($payload);
        $signature = ShopeeSignature::pushSign($customUrl, $body, 'test_partner_key');

        $this->call('POST', '/api/v1/shopee/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);

        Queue::assertPushed(ProcessShopeeWebhook::class, 1);
    }

    public function test_push_partner_key_takes_precedence(): void
    {
        Queue::fake();

        $pushKey = 'separate_push_key';
        config(['services.shopee.push_partner_key' => $pushKey]);

        $payload = $this->orderPayload();
        $body = json_encode($payload);
        $signature = ShopeeSignature::pushSign(self::URL, $body, $pushKey);

        $this->call('POST', '/api/v1/shopee/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);

        Queue::assertPushed(ProcessShopeeWebhook::class, 1);
    }

    public function test_verification_probe_without_auth_returns_200(): void
    {
        Queue::fake();

        $this->call('POST', '/api/v1/shopee/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '')->assertStatus(200);

        Queue::assertNothingPushed();
    }
}
