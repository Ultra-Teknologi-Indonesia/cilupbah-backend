<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Modules\Channel\Services\LazadaClient;
use Tests\TestCase;

class LazadaClientRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);
    }

    public function test_retries_on_frequency_limit_then_succeeds(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/*' => Http::sequence()
                ->push(['code' => 'ApiCallLimit', 'message' => 'Api access frequency exceeds the limit. this ban will last 1 seconds'], 200)
                ->push(['code' => '0', 'data' => ['ok' => true]], 200),
        ]);

        $result = (new LazadaClient())->request('GET', '/seller/get', [], 'tok');

        $this->assertSame('0', $result['code']);
        $this->assertTrue($result['data']['ok']);
        Http::assertSentCount(2);
    }

    public function test_retries_on_rpc_timeout_then_succeeds(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/*' => Http::sequence()
                ->push(['code' => 'SystemError', 'message' => 'The request has failed due to RPC timeout'], 200)
                ->push(['code' => '0', 'data' => ['products' => []]], 200),
        ]);

        $result = (new LazadaClient())->request('GET', '/products/get', ['filter' => 'all'], 'tok');

        $this->assertSame('0', $result['code']);
        Http::assertSentCount(2);
    }

    public function test_does_not_retry_non_transient_error(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/*' => Http::response(['code' => 'InvalidParameter', 'message' => 'bad param'], 200),
        ]);

        $this->expectException(\Exception::class);

        try {
            (new LazadaClient())->request('GET', '/orders/get', [], 'tok');
        } finally {
            Http::assertSentCount(1);
        }
    }
}
