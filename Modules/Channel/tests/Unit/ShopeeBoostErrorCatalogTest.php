<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Support\ShopeeBoostErrorCatalog;
use PHPUnit\Framework\TestCase;

class ShopeeBoostErrorCatalogTest extends TestCase
{
    public function test_known_per_item_failure_is_translated(): void
    {
        $message = ShopeeBoostErrorCatalog::failureReason('can not boost item repeatedly');

        $this->assertStringContainsString('baru saja dinaikkan', $message);
        $this->assertStringNotContainsString('can not boost', $message);
    }

    public function test_unknown_per_item_failure_uses_safe_indonesian_fallback(): void
    {
        $message = ShopeeBoostErrorCatalog::failureReason('some future english error');

        $this->assertSame(ShopeeBoostErrorCatalog::GENERIC_FAILURE, $message);
    }

    public function test_unknown_exception_does_not_leak_raw_message(): void
    {
        $message = ShopeeBoostErrorCatalog::exceptionMessage(new \RuntimeException('partner key leaked'));

        $this->assertSame(ShopeeBoostErrorCatalog::GENERIC_API_FAILURE, $message);
        $this->assertStringNotContainsString('partner key', $message);
    }

    public function test_boost_api_error_does_not_expose_raw_parameter_detail(): void
    {
        $message = ShopeeBoostErrorCatalog::exceptionMessage(new ShopeeApiException(
            'error_param',
            'user_fixable',
            'raw english message',
            'raw english message',
            'item_id_list is invalid',
        ));

        $this->assertStringContainsString('Data produk', $message);
        $this->assertStringNotContainsString('raw english', $message);
    }
}
