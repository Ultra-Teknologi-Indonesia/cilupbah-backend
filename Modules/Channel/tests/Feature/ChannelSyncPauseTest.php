<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Channel\Models\ChannelSyncSetting;
use Modules\Channel\Services\ChannelSyncSettingService;
use Tests\TestCase;

final class ChannelSyncPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);
        parent::tearDown();
    }

    public function test_auto_pause_does_not_run_before_noon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 11:59:00', 'Asia/Jakarta'));

        self::assertFalse(app(ChannelSyncSettingService::class)->autoPauseIfDue());
        self::assertTrue(app(ChannelSyncSettingService::class)->isEnabled());
    }

    public function test_auto_pause_runs_once_at_noon_and_records_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:01', 'Asia/Jakarta'));
        $service = app(ChannelSyncSettingService::class);

        self::assertTrue($service->autoPauseIfDue());
        self::assertFalse($service->isEnabled());
        self::assertSame(
            ChannelSyncSettingService::AUTO_PAUSE_REASON,
            ChannelSyncSetting::query()->value('pause_reason'),
        );
        self::assertFalse($service->autoPauseIfDue());
    }

    public function test_pull_start_is_clamped_to_last_resume_time(): void
    {
        $service = app(ChannelSyncSettingService::class);
        $service->setEnabled(false);

        Carbon::setTestNow(Carbon::parse('2026-09-22 13:00:00', 'Asia/Jakarta'));
        $service->setEnabled(true);

        $resumedAt = $service->current()->resumed_at;
        $requested = $resumedAt->copy()->subHour();
        self::assertSame(
            $resumedAt->toIso8601String(),
            $service->effectiveInboundStart($requested)->toIso8601String(),
        );
    }

    public function test_auto_pause_is_one_time_even_after_manual_resume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:01', 'Asia/Jakarta'));
        $service = app(ChannelSyncSettingService::class);

        self::assertTrue($service->autoPauseIfDue());

        $service->setEnabled(true);

        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:01', 'Asia/Jakarta'));

        self::assertFalse($service->autoPauseIfDue());
        self::assertTrue($service->isEnabled());
    }
}
