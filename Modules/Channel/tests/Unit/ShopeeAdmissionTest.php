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
            public function admit(): void
            {
                $this->throttle();
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
}
