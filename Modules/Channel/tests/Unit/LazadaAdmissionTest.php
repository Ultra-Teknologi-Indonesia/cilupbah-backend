<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Support\ChannelErrorClassifier;
use Modules\Channel\Support\LazadaErrorCatalog;
use Tests\TestCase;

final class LazadaAdmissionTest extends TestCase
{
    private function client(): LazadaClient
    {
        return new class extends LazadaClient
        {
            public function admit(): void
            {
                $this->throttle();
            }
        };
    }

    public function test_waiter_reacquires_slot_and_releases_lock(): void
    {
        config(['ratelimit.lazada_admission_wait_seconds' => 1]);
        RateLimiter::shouldReceive('attempt')->twice()->andReturn(false, true);
        $this->client()->admit();
        $lock = Cache::lock('lazada-api-throttle-lock', 2);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_full_window_rejects_without_bypassing_limit_or_long_sleep(): void
    {
        config(['ratelimit.lazada_admission_wait_seconds' => 0]);
        RateLimiter::shouldReceive('attempt')->once()->andReturn(false);
        $start = microtime(true);
        try {
            $this->client()->admit();
            $this->fail('Quota must never be bypassed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dicoba kembali', $e->getMessage());
            $this->assertTrue(ChannelErrorClassifier::isRetryable('lazada', $e));
            $this->assertSame('retryable', LazadaErrorCatalog::resolve($e->getMessage())['category']);
        }
        $this->assertLessThan(1, microtime(true) - $start);
        $lock = Cache::lock('lazada-api-throttle-lock', 2);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
