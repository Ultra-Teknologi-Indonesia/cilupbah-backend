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
        {--dedupe-days=7 : Jangan kirim ulang alert untuk toko yang sama dalam N hari}';

    protected $description = 'Kirim peringatan dini saat koneksi channel mendekati/kedaluwarsa & perlu otorisasi ulang';

    private const NOTIF_TYPE = 'channel_reauth_required';

    private const NOTIF_PERMISSION = 'view-integrasi-channel';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $days = max(0, (int) $this->option('days'));
        $dedupeDays = max(1, (int) $this->option('dedupe-days'));
        $threshold = now()->addDays($days);

        $shops = ChannelShop::query()
            ->with('channel')
            ->whereNull('disconnected_at')
            ->where('is_active', true)
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($shops as $shop) {
            if (! $this->needsAlert($shop, $threshold) || $this->alreadyAlerted($shop, $dedupeDays)) {
                $skipped++;
                continue;
            }

            $this->dispatchAlert($dispatcher, $shop);
            $sent++;
        }

        $this->info("Alert re-auth channel: {$sent} terkirim, {$skipped} dilewati.");

        return self::SUCCESS;
    }

    private function needsAlert(ChannelShop $shop, \Illuminate\Support\Carbon $threshold): bool
    {

        if (empty($shop->access_token)) {
            return true;
        }

        return $shop->refresh_token_expires_at !== null
            && $shop->refresh_token_expires_at->lte($threshold);
    }

    private function alreadyAlerted(ChannelShop $shop, int $dedupeDays): bool
    {
        return Notification::query()
            ->where('type', self::NOTIF_TYPE)
            ->where('data->shop_id', $shop->id)
            ->where('created_at', '>=', now()->subDays($dedupeDays))
            ->exists();
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
