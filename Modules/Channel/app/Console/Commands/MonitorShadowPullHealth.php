<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;

class MonitorShadowPullHealth extends Command
{
    protected $signature = 'channel:monitor-shadow-pull';

    protected $description = 'Alert saat tarik order shadow berhenti atau satu toko terus gagal.';

    public function handle(): int
    {
        $staleMinutes = (int) env('SHADOW_PULL_STALE_MINUTES', 60);
        $shops = ChannelShop::with('channel')
            ->where('is_active', true)
            ->where('is_shadow_mode', true)
            ->get();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko shadow yang perlu dipantau.');

            return self::SUCCESS;
        }

        $alerts = 0;

        foreach ($shops as $shop) {
            $alerts += $this->checkNeverPulled($shop);
            $alerts += $this->checkStale($shop, $staleMinutes);
            $alerts += $this->checkPersistentError($shop);
        }

        $this->info($alerts > 0 ? "{$alerts} alert dikirim." : 'Sehat: tarik order shadow berjalan normal.');

        return self::SUCCESS;
    }

    private function checkNeverPulled(ChannelShop $shop): int
    {
        if ($shop->shadow_last_pulled_at || ! $shop->shadow_started_at) {
            return 0;
        }

        if ($shop->shadow_started_at->greaterThan(now()->subMinutes(30))) {
            return 0;
        }

        $this->pushAlert("Toko {$shop->shop_name} sudah shadow sejak {$shop->shadow_started_at->diffForHumans()} tapi belum pernah berhasil menarik order.", [
            'shop_id' => $shop->shop_id,
        ]);

        return 1;
    }

    private function checkStale(ChannelShop $shop, int $staleMinutes): int
    {
        if (! $shop->shadow_last_pulled_at) {
            return 0;
        }

        if ($shop->shadow_last_pulled_at->greaterThan(now()->subMinutes($staleMinutes))) {
            return 0;
        }

        $this->pushAlert("Tarik order shadow {$shop->shop_name} macet: terakhir sukses {$shop->shadow_last_pulled_at->diffForHumans()}.", [
            'shop_id'               => $shop->shop_id,
            'shadow_last_pulled_at' => $shop->shadow_last_pulled_at->toIso8601String(),
            'stale_minutes'         => $staleMinutes,
        ]);

        return 1;
    }

    private function checkPersistentError(ChannelShop $shop): int
    {
        if (! $shop->last_order_error_at || ! $shop->last_order_error) {
            return 0;
        }

        $errorIsNewerThanSuccess = $shop->shadow_last_pulled_at === null
            || $shop->last_order_error_at->greaterThan($shop->shadow_last_pulled_at);

        if (! $errorIsNewerThanSuccess) {
            return 0;
        }

        $this->pushAlert("Toko {$shop->shop_name} gagal menarik order: {$shop->last_order_error}", [
            'shop_id'  => $shop->shop_id,
            'error_at' => $shop->last_order_error_at->toIso8601String(),
        ]);

        return 1;
    }

    private function pushAlert(string $message, array $context): void
    {
        Log::warning('[shadow-pull-health] ' . $message, $context);

        if (function_exists('Sentry\\captureMessage')) {
            \Sentry\captureMessage('[shadow-pull-health] ' . $message);
        }

        $this->warn($message);
    }
}
