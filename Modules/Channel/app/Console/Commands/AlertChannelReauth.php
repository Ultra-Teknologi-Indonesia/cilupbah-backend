<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Support\ChannelReauthCopy;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationDispatcher;

class AlertChannelReauth extends Command
{
    protected $signature = 'channel:alert-reauth
        {--days=3 : Ambang peringatan dini (hari) sebelum refresh token kedaluwarsa}
        {--dedupe-days=7 : Jangan kirim ulang alert re-auth untuk toko yang sama dalam N hari}
        {--failure-dedupe-hours=6 : Jangan kirim ulang alert kegagalan perpanjangan untuk toko yang sama dalam N jam}';

    protected $description = 'Kirim peringatan saat koneksi channel perlu otorisasi ulang atau perpanjangan token otomatisnya gagal';

    private const NOTIF_TYPE = 'channel_reauth_required';

    private const NOTIF_TYPE_REFRESH_FAILED = 'channel_refresh_failed';

    private const NOTIF_PERMISSION = 'view-integrasi-channel';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $days = max(0, (int) $this->option('days'));
        $dedupeDays = max(1, (int) $this->option('dedupe-days'));
        $failureDedupeHours = max(1, (int) $this->option('failure-dedupe-hours'));
        $threshold = now()->addDays($days);

        $shops = ChannelShop::query()
            ->with('channel')
            ->whereNull('disconnected_at')
            ->where('is_active', true)
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($shops as $shop) {
            $reason = $this->alertReason($shop, $threshold);

            if ($reason === null) {
                $skipped++;
                continue;
            }

            $isReauth = $reason === 'reauth';
            $type = $isReauth ? self::NOTIF_TYPE : self::NOTIF_TYPE_REFRESH_FAILED;
            $since = $isReauth ? now()->subDays($dedupeDays) : now()->subHours($failureDedupeHours);

            if ($this->alreadyAlerted($shop, $type, $since)) {
                $skipped++;
                continue;
            }

            $isReauth
                ? $this->dispatchAlert($dispatcher, $shop)
                : $this->dispatchRefreshFailedAlert($dispatcher, $shop);

            $sent++;
        }

        $this->info("Alert koneksi channel: {$sent} terkirim, {$skipped} dilewati.");

        return self::SUCCESS;
    }

    private function alertReason(ChannelShop $shop, \Illuminate\Support\Carbon $threshold): ?string
    {
        if (empty($shop->access_token)) {
            return 'reauth';
        }

        if ($shop->refresh_token_expires_at !== null && $shop->refresh_token_expires_at->lte($threshold)) {
            return 'reauth';
        }

        if ($shop->integration_status === 'error') {
            return 'refresh_failed';
        }

        return null;
    }

    private function alreadyAlerted(ChannelShop $shop, string $type, \Illuminate\Support\Carbon $since): bool
    {
        return Notification::query()
            ->where('type', $type)
            ->where('data->shop_id', $shop->id)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    private function dispatchRefreshFailedAlert(NotificationDispatcher $dispatcher, ChannelShop $shop): void
    {
        $code = $shop->channel?->code;
        $name = ChannelReauthCopy::channelName($code);
        $sebab = $shop->last_error ?: 'Integrasi bermasalah.';

        $message = "Perpanjangan otomatis koneksi {$name} toko \"{$shop->shop_name}\" gagal. {$sebab} "
            . 'Sinkronisasi pesanan & stok bisa terhenti bila dibiarkan.';

        $dispatcher->toPermission(self::NOTIF_PERMISSION, [
            'type' => self::NOTIF_TYPE_REFRESH_FAILED,
            'title' => "Perpanjangan koneksi {$name} gagal",
            'message' => $message,
            'data' => [
                'shop_id' => $shop->id,
                'channel_shop_id' => $shop->shop_id,
                'channel_code' => $code,
                'shop_name' => $shop->shop_name,
                'last_error' => $shop->last_error,
            ],
        ]);

        Log::warning('Alert kegagalan perpanjangan token channel terkirim', [
            'shop_id' => $shop->shop_id,
            'channel' => $code,
            'last_error' => $shop->last_error,
        ]);
    }

    private function dispatchAlert(NotificationDispatcher $dispatcher, ChannelShop $shop): void
    {
        $code = $shop->channel?->code;
        $name = ChannelReauthCopy::channelName($code);
        $expiry = $shop->refresh_token_expires_at;

        $when = $expiry
            ? ($expiry->isPast() ? 'sudah kedaluwarsa' : 'akan kedaluwarsa ' . $expiry->format('d M Y'))
            : 'perlu disambung ulang';

        $message = "Koneksi {$name} toko \"{$shop->shop_name}\" {$when}. "
            . 'Hubungkan ulang agar sinkronisasi pesanan & stok tidak terputus.';

        $dispatcher->toPermission(self::NOTIF_PERMISSION, [
            'type' => self::NOTIF_TYPE,
            'title' => "Koneksi {$name} perlu dihubungkan ulang",
            'message' => $message,
            'data' => [
                'shop_id' => $shop->id,
                'channel_shop_id' => $shop->shop_id,
                'channel_code' => $code,
                'shop_name' => $shop->shop_name,
                'action' => 'reauth',
                'refresh_token_expires_at' => $expiry?->toIso8601String(),
            ],
        ]);

        Log::info('Alert re-auth channel terkirim', [
            'shop_id' => $shop->shop_id,
            'channel' => $code,
            'refresh_token_expires_at' => $expiry?->toIso8601String(),
        ]);
    }
}
