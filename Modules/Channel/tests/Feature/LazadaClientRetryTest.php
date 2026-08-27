<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
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

    public function test_interactive_request_can_use_a_single_bounded_attempt(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/*' => Http::response([
                'code' => 'SystemError',
                'message' => 'The request has failed due to RPC timeout',
            ], 200),
        ]);

        $this->expectException(\Exception::class);

        try {
            (new LazadaClient())->request('GET', '/products/get', [], 'tok', 2, 1);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_error_does_not_expose_signed_url_or_access_token(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/*' => function () {
                throw new ConnectionException(
                    'cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received for '
                    . 'https://api.lazada.co.id/rest/products/get?access_token=secret-token&sign=secret-sign'
                );
            },
        ]);

        try {
            (new LazadaClient())->request('GET', '/products/get', [], 'secret-token', 1, 1);
            $this->fail('Expected a Lazada connection exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('request timeout setelah 1 detik', $e->getMessage());
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
            $this->assertStringNotContainsString('secret-sign', $e->getMessage());
            $this->assertStringNotContainsString('access_token=', $e->getMessage());
        }
    }
}
