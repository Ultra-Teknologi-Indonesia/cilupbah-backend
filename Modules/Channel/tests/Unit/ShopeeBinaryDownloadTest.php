<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Services\ShopeeClient;
use Tests\TestCase;

final class ShopeeBinaryDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.shopee.partner_id' => '123', 'services.shopee.partner_key' => 'test-only-key',
            'services.shopee.host' => 'https://shopee.test']);
    }

    private function download(): array
    {
        return app(ShopeeClient::class)->requestBinary('/api/v2/logistics/download_shipping_document',
            ['order_list' => [['order_sn' => 'ORDER-123']]], 'test-only-token', '123');
    }

    public function test_json_error_on_http_failure_keeps_token_refresh_classification(): void
    {
        Http::fake(['shopee.test/*' => Http::response(['error' => 'error_auth_token', 'message' => 'Expired'], 401)]);
        $this->expectException(TokenExpiredException::class);
        $this->download();
    }

    public function test_expired_document_error_is_normalized_and_retryable(): void
    {
        Http::fake(['shopee.test/*' => Http::response(['error' => 'logistics.shipping_document_should_print_first'], 200)]);
        try {
            $this->download();
            $this->fail('JSON error must not be treated as PDF.');
        } catch (ShopeeApiException $exception) {
            $this->assertSame('logistics_shipping_document_should_print_first', $exception->errorCode);
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_http_429_html_is_not_accepted_as_binary_label(): void
    {
        Http::fake(['shopee.test/*' => Http::response('<html>Busy</html>', 429, ['Content-Type' => 'text/html'])]);
        try {
            $this->download();
            $this->fail('HTTP error must not be returned as PDF.');
        } catch (ShopeeApiException $exception) {
            $this->assertSame('http_429', $exception->errorCode);
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_download_size_limit_is_enforced_even_without_progress_callback(): void
    {
        config(['bulk-labels.mass_download_max_bytes' => 8]);
        Http::fake(['shopee.test/*' => Http::response('%PDF-123456789', 200, ['Content-Type' => 'application/pdf'])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('melebihi batas aman');
        $this->download();
    }
}
