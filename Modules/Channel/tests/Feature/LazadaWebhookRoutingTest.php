<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaOrderService;
use Tests\TestCase;

class LazadaWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function process(array $payload, $order, $download, $auth): void
    {
        (new ProcessLazadaWebhook($payload))->handle($order, $download, $auth);
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

        // Token-expiry asli = message_type 8 (terkonfirmasi dari payload staging).
        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 8, 'data' => []],
            Mockery::mock(LazadaOrderService::class),
            Mockery::mock(ChannelDownloadService::class),
            $auth,
        );
    }

    public function test_product_edit_type_4_repulls_product_not_token(): void
    {
        // Regression: sebelumnya type 4 salah dianggap token-expiry; payload staging
        // membuktikan type 4 = produk-edit (punya item_id) → harus re-download produk.
        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('lazada', 'LZ1', 'IT-4');

        $auth = Mockery::mock(LazadaAuthService::class);
        $auth->shouldReceive('refreshStoreToken')->never();

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 4, 'data' => ['item_id' => 'IT-4', 'action' => 'EDITED_UPDATED']],
            Mockery::mock(LazadaOrderService::class),
            $download,
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
            Mockery::mock(ChannelDownloadService::class),
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
            Mockery::mock(ChannelDownloadService::class),
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_product_edited_repulls_product(): void
    {
        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('lazada', 'LZ1', 'IT-1');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 1, 'data' => ['item_id' => 'IT-1', 'price' => 1000]],
            Mockery::mock(LazadaOrderService::class),
            $download,
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_product_qc_only_still_repulls(): void
    {

        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('lazada', 'LZ1', 'IT-1');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 1, 'data' => ['item_id' => 'IT-1', 'qc_status' => 'approved']],
            Mockery::mock(LazadaOrderService::class),
            $download,
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_product_create_type_3_repulls_product(): void
    {
        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('lazada', 'LZ1', 'IT-9');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 3, 'data' => ['item_id' => 'IT-9', 'action' => 'PUBLISHED']],
            Mockery::mock(LazadaOrderService::class),
            $download,
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_explicit_reverse_type_10_repulls_order(): void
    {
        $order = Mockery::mock(LazadaOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('LZ1', 'TO-1');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 10, 'data' => ['trade_order_id' => 'TO-1', 'reverse_order_id' => 'RO-9', 'reverse_status' => 'RTM_INIT']],
            $order,
            Mockery::mock(ChannelDownloadService::class),
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_fulfillment_type_14_delivered_repulls_order(): void
    {
        $order = Mockery::mock(LazadaOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('LZ1', 'TO-14');

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 14, 'data' => ['trade_order_id' => 'TO-14', 'status' => 'DELIVERED']],
            $order,
            Mockery::mock(ChannelDownloadService::class),
            Mockery::mock(LazadaAuthService::class),
        );
    }

    public function test_fulfillment_type_14_micro_status_does_not_repull(): void
    {
        $order = Mockery::mock(LazadaOrderService::class);
        $order->shouldReceive('pullOrderById')->never();

        $this->process(
            ['seller_id' => 'LZ1', 'message_type' => 14, 'data' => ['trade_order_id' => 'TO-14', 'status' => 'INFO_ST_DOMESTIC_OUT_FOR_DELIVERY']],
            $order,
            Mockery::mock(ChannelDownloadService::class),
            Mockery::mock(LazadaAuthService::class),
        );
    }
}
