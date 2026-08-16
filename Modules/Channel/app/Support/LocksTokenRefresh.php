<?php

namespace Modules\Channel\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Modules\Channel\Exceptions\ChannelTokenException;
use Modules\Channel\Models\ChannelShop;

trait LocksTokenRefresh
{
    private const REFRESH_LOCK_SECONDS = 60;

    private const REFRESH_LOCK_WAIT_SECONDS = 20;

    protected function lockedTokenRefresh(string $id, string $channelCode, ?string $tokenSebelum, callable $refresh): array
    {
        $tokenSebelum = (string) $tokenSebelum;

        try {
            return Cache::lock('channel:refresh-token:' . $id, self::REFRESH_LOCK_SECONDS)
                ->block(
                    self::REFRESH_LOCK_WAIT_SECONDS,
                    fn () => $this->tokenRotatedElsewhere($id, $tokenSebelum) ?? $refresh(),
                );
        } catch (LockTimeoutException $e) {
            $rotated = $this->tokenRotatedElsewhere($id, $tokenSebelum);

            if ($rotated !== null) {
                return $rotated;
            }

            throw new ChannelTokenException(
                ChannelReauthCopy::refreshFailure($channelCode, false),
                permanent: false,
                channelCode: $channelCode,
                rawMessage: 'Perpanjangan token sedang dijalankan proses lain dan belum selesai',
            );
        }
    }

    protected function tokenRotatedElsewhere(string $id, string $tokenSebelum): ?array
    {
        $shop = ChannelShop::find($id);

        if (! $shop) {
            return null;
        }

        $isDifferentToken = (string) $shop->access_token !== $tokenSebelum;
        $isValidFuture = $shop->token_expires_at && $shop->token_expires_at->gt(now()->addMinutes(5));

        if (! $isDifferentToken && ! $isValidFuture) {
            return null;
        }

        if (! $shop->token_expires_at || $shop->token_expires_at->isPast()) {
            return null;
        }

        return [
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'token_expires_at' => $shop->token_expires_at->toIso8601String(),
        ];
    }
}
