<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\LazadaProductService;
use Tests\TestCase;

class LazadaWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function process(array $payload, $order, $product, $auth): void
    {
        (new ProcessLazadaWebhook($payload))->handle($order, $product, $auth);
    }

    public function test_token_expiry_message_triggers_refresh(): void
    {
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'is_active' => true,
        ]);

        $auth = Mockery::mock(LazadaAuthService::class);
        $auth->shouldReceive('refreshStoreToken')->once()->with((string) $shop->id);

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 4, 'data' => []],
            Mockery::mock(LazadaOrderService::class),
            Mockery::mock(LazadaProductService::class),
            $auth,
        );
    }

    public function test_token_expiry_detected_by_content_even_if_message_type_unknown(): void
    {
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'is_active' => true,
        ]);

        $auth = Mockery::mock(LazadaAuthService::class);
        $auth->shouldReceive('refreshStoreToken')->once()->with((string) $shop->id);

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 99, 'data' => ['alert' => 'access_token will expire soon']],
            Mockery::mock(LazadaOrderService::class),
            Mockery::mock(LazadaProductService::class),
            $auth,
        );
    }

    public function test_reverse_message_repulls_order(): void
    {
        $order = Mockery::mock(LazadaOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('LZ1', 'RO-1');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 99, 'data' => ['reverse_order_id' => 'RO-1']],
            $order,
            Mockery::mock(LazadaProductService::class),
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_product_edited_repulls_product(): void
    {
        $product = Mockery::mock(LazadaProductService::class);
        $product->shouldReceive('pullProductById')->once()->with('LZ1', 'IT-1');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 1, 'data' => ['item_id' => 'IT-1', 'price' => 1000]],
            Mockery::mock(LazadaOrderService::class),
            $product,
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_product_qc_only_does_not_repull(): void
    {
        $product = Mockery::mock(LazadaProductService::class);
        $product->shouldReceive('pullProductById')->never();

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 1, 'data' => ['item_id' => 'IT-1', 'qc_status' => 'approved']],
            Mockery::mock(LazadaOrderService::class),
            $product,
            Mockery::mock(LazadaAuthService::class),
        );
    }
}
