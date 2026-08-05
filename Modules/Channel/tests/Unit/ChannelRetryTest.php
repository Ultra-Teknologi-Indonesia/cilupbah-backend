<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Support\ChannelErrorClassifier;
use Modules\Channel\Support\ChannelRetry;
use Modules\Channel\Support\ShopeeErrorCatalog;
use Modules\Channel\Support\TikTokErrorCatalog;
use Tests\TestCase;

class ChannelRetryTest extends TestCase
{
    public function test_lazada_rate_limit_is_retryable(): void
    {
        $e = new \Exception('Lazada API Error: Seller access frequency exceeds the limit. this ban will last 1 seconds');

        $this->assertTrue(ChannelErrorClassifier::isRetryable('lazada', $e));
        $this->assertSame(1, ChannelErrorClassifier::retryAfterSeconds($e));
    }

    public function test_lazada_create_failed_is_not_retryable(): void
    {
        $e = new \Exception('Lazada API Error: E500: Create product failed');

        $this->assertFalse(ChannelErrorClassifier::isRetryable('lazada', $e));
    }

    public function test_shopee_exception_uses_its_category(): void
    {
        $retryable = new ShopeeApiException('error_system_busy', ShopeeErrorCatalog::RETRYABLE, 'busy');
        $fatal = new ShopeeApiException('error_item_not_found', ShopeeErrorCatalog::FATAL, 'not found');

        $this->assertTrue(ChannelErrorClassifier::isRetryable('shopee', $retryable));
        $this->assertFalse(ChannelErrorClassifier::isRetryable('shopee', $fatal));
    }

    public function test_tiktok_exception_uses_its_category(): void
    {
        $retryable = new TikTokApiException('12300000', TikTokErrorCatalog::RETRYABLE, 'busy');

        $this->assertTrue(ChannelErrorClassifier::isRetryable('tiktok', $retryable));
    }

    public function test_token_expired_is_not_retryable(): void
    {
        $this->assertFalse(ChannelErrorClassifier::isRetryable('lazada', new TokenExpiredException('shop-1')));
    }

    public function test_generic_timeout_is_retryable_validation_is_not(): void
    {
        $this->assertTrue(ChannelErrorClassifier::isRetryable('shopee', new \RuntimeException('Shopee API HTTP Error [502]: bad gateway')));
        $this->assertFalse(ChannelErrorClassifier::isRetryable('shopee', new \RuntimeException('parameter validate fail')));
    }

    public function test_retry_succeeds_after_transient_failures(): void
    {
        $attempts = 0;

        $result = ChannelRetry::run('lazada', function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \Exception('Lazada API Error: Seller access frequency exceeds the limit');
            }

            return 'ok';
        }, 5, fn () => null);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_retry_rethrows_non_retryable_immediately(): void
    {
        $attempts = 0;

        $this->expectException(\Exception::class);

        try {
            ChannelRetry::run('lazada', function () use (&$attempts) {
                $attempts++;
                throw new \Exception('Lazada API Error: E500: Create product failed');
            }, 5, fn () => null);
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_retry_gives_up_after_max_attempts(): void
    {
        $attempts = 0;

        try {
            ChannelRetry::run('lazada', function () use (&$attempts) {
                $attempts++;
                throw new \Exception('Lazada API Error: Seller access frequency exceeds the limit');
            }, 3, fn () => null);
            $this->fail('Seharusnya melempar exception setelah kehabisan percobaan.');
        } catch (\Exception $e) {
            $this->assertSame(3, $attempts);
        }
    }
}
