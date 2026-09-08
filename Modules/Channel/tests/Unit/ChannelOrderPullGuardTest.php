<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Exceptions\ChannelOrderNotAvailableException;
use Modules\Channel\Support\ChannelErrorClassifier;
use Modules\Channel\Support\ChannelOrderPullGuard;
use PHPUnit\Framework\TestCase;

final class ChannelOrderPullGuardTest extends TestCase
{
    public function test_zero_result_is_retryable_failure(): void
    {
        try {
            ChannelOrderPullGuard::requirePersisted('tiktok', 'SHOP-1', 'ORDER-1', 0);
            self::fail('Expected an order-not-available exception.');
        } catch (ChannelOrderNotAvailableException $exception) {
            self::assertSame('tiktok', $exception->channel);
            self::assertSame('SHOP-1', $exception->shopId);
            self::assertSame('ORDER-1', $exception->orderId);
            self::assertTrue(ChannelErrorClassifier::isRetryable('tiktok', $exception));
        }
    }

    public function test_positive_result_is_accepted(): void
    {
        ChannelOrderPullGuard::requirePersisted('shopee', 'SHOP-2', 'ORDER-2', 1);

        self::assertTrue(true);
    }
}
