<?php

namespace Modules\Channel\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\ManualStockResyncAllJob;
use Modules\Channel\Models\ChannelSyncSetting;

class ChannelSyncSettingService
{
    public const CACHE_KEY = 'channel_sync_enabled';

    public const TIMEZONE = 'Asia/Jakarta';

    public const MANUAL_PAUSE_REASON = 'manual';

    public const AUTO_PAUSE_REASON = 'auto_schedule';

    /** @deprecated Inbound pulls must respect the global pause gate. */
    public static function withInboundBypass(callable $callback): mixed
    {
        return $callback();
    }

    public function current(): ChannelSyncSetting
    {
        return ChannelSyncSetting::query()->firstOrCreate([], ['sync_enabled' => true]);
    }

    public function isEnabled(): bool
    {
        return (bool) Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn () => $this->current()->sync_enabled,
        );
    }

    public function isPaused(): bool
    {
        return ! $this->isEnabled();
    }

    public function effectiveInboundStart(CarbonInterface $requestedStart): Carbon
    {
        $resumedAt = $this->current()->resumed_at;

        if (! $resumedAt || $requestedStart->greaterThanOrEqualTo($resumedAt)) {
            return Carbon::instance($requestedStart);
        }

        return Carbon::instance($resumedAt);
    }

    public function status(): array
    {
        $setting = $this->current();
        $localNow = Carbon::now(config('channel.sync_timezone', self::TIMEZONE));
        $autoPauseAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $localNow->format('Y-m-d').' '.config('channel.sync_auto_pause_at', '12:00'),
            config('channel.sync_timezone', self::TIMEZONE),
        );

        return [
            'sync_enabled' => (bool) $setting->sync_enabled,
            'paused_at' => $setting->paused_at?->toIso8601String(),
            'resumed_at' => $setting->resumed_at?->toIso8601String(),
            'pause_reason' => $setting->pause_reason,
            'auto_pause_at' => $autoPauseAt->toIso8601String(),
            'auto_pause_timezone' => config('channel.sync_timezone', self::TIMEZONE),
            'auto_pause_due' => $localNow->greaterThanOrEqualTo($autoPauseAt),
        ];
    }

    public function setEnabled(bool $enabled): ChannelSyncSetting
    {
        $setting = $this->current();
        $wasEnabled = (bool) $setting->sync_enabled;

        $setting->forceFill([
            'sync_enabled' => $enabled,
            'paused_at' => $enabled ? $setting->paused_at : now(),
            'resumed_at' => $enabled ? now() : $setting->resumed_at,
            'pause_reason' => $enabled ? null : self::MANUAL_PAUSE_REASON,
        ])->save();
        Cache::forget(self::CACHE_KEY);

        if ($enabled && ! $wasEnabled) {
            ManualStockResyncAllJob::dispatch([]);
        }

        return $setting->refresh();
    }

    public function autoPauseIfDue(): bool
    {
        $timezone = config('channel.sync_timezone', self::TIMEZONE);
        $localNow = Carbon::now($timezone);
        $autoPauseAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $localNow->format('Y-m-d').' '.config('channel.sync_auto_pause_at', '12:00'),
            $timezone,
        );

        if ($localNow->lessThan($autoPauseAt)) {
            return false;
        }

        $didPause = false;
        DB::transaction(function () use ($localNow, &$didPause): void {
            $setting = ChannelSyncSetting::query()->lockForUpdate()->firstOrCreate(
                [],
                ['sync_enabled' => true],
            );

            if (! $setting->sync_enabled
                || $setting->auto_paused_on?->toDateString() === $localNow->toDateString()) {
                return;
            }

            $setting->forceFill([
                'sync_enabled' => false,
                'paused_at' => now(),
                'auto_paused_on' => $localNow->toDateString(),
                'pause_reason' => self::AUTO_PAUSE_REASON,
            ])->save();
            $didPause = true;
        });

        if ($didPause) {
            Cache::forget(self::CACHE_KEY);
        }

        return $didPause;
    }
}
