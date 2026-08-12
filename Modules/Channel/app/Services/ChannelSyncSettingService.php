<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\ManualStockResyncAllJob;
use Modules\Channel\Models\ChannelSyncSetting;

class ChannelSyncSettingService
{
    public const CACHE_KEY = 'channel_sync_enabled';

    protected static bool $inboundBypass = false;

    public static function withInboundBypass(callable $callback): mixed
    {
        $previous = self::$inboundBypass;
        self::$inboundBypass = true;

        try {
            return $callback();
        } finally {
            self::$inboundBypass = $previous;
        }
    }

    public static function inboundBypassActive(): bool
    {
        return self::$inboundBypass;
    }

    public function current(): ChannelSyncSetting
    {
        return ChannelSyncSetting::query()->firstOrCreate([], ['sync_enabled' => true]);
    }

    public function isEnabled(): bool
    {
        if (self::$inboundBypass) {
            return true;
        }

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

    public function setEnabled(bool $enabled): ChannelSyncSetting
    {
        $setting = $this->current();
        $wasEnabled = (bool) $setting->sync_enabled;

        $setting->update(['sync_enabled' => $enabled]);
        Cache::forget(self::CACHE_KEY);

        if ($enabled && ! $wasEnabled) {
            ManualStockResyncAllJob::dispatch([]);
        }

        return $setting->refresh();
    }
}
