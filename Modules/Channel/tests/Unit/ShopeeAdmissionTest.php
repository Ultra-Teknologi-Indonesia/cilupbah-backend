<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Services\ShopeeClient;
use Tests\TestCase;

final class ShopeeAdmissionTest extends TestCase
{
    private function client(): ShopeeClient
    {
        return new class extends ShopeeClient
        {
            public function admit(?string $path = null): void
            {
                $this->throttle($path);
            }
        };
    }

    public function test_waiter_reacquires_permission_instead_of_bypassing_limit(): void
    {
        config(['ratelimit.shopee_admission_wait_seconds' => 1]);
        RateLimiter::shouldReceive('attempt')->twice()->andReturn(false, true);
        $this->client()->admit();
        $lock = Cache::lock('shopee-api:admission', 2);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_full_window_fails_retryably_and_does_not_hold_lock(): void
    {
        config(['ratelimit.shopee_admission_wait_seconds' => 0]);
        RateLimiter::shouldReceive('attempt')->once()->andReturn(false);
        try {
            $this->client()->admit();
            $this->fail('Must not bypass the limit');
        } catch (ShopeeApiException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame('local_rate_limit', $exception->errorCode);
        }
        $lock = Cache::lock('shopee-api:admission', 2);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_label_cannot_consume_reserved_capacity_when_critical_requests_are_active(): void
    {
        config(['ratelimit.shopee_admission_wait_seconds' => 0, 'ratelimit.shopee_critical_priority' => true]);
        Cache::store(config('cache.limiter'))->put('shopee-api:critical-demand', true, 2);
        RateLimiter::shouldReceive('tooManyAttempts')->once()->with('shopee-api:noncritical', 2)->andReturnTrue();
        RateLimiter::shouldReceive('attempt')->never();
        $this->expectException(ShopeeApiException::class);
        $this->client()->admit('/api/v2/logistics/download_shipping_document');
    }

    public function test_order_detail_still_obeys_the_global_limit_and_announces_critical_demand(): void
    {
        config(['ratelimit.shopee_admission_wait_seconds' => 0, 'ratelimit.shopee_critical_priority' => true]);
        RateLimiter::shouldReceive('tooManyAttempts')->never();
        RateLimiter::shouldReceive('attempt')->once()->with('shopee-api', 4, \Mockery::type('Closure'), 1)->andReturnTrue();
        $this->client()->admit('/api/v2/order/get_order_detail');
        $this->assertTrue(Cache::store(config('cache.limiter'))->has('shopee-api:critical-demand'));
    }
}
