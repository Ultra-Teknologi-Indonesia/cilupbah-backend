<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Exceptions\UnsupportedShadowChannelException;
use Modules\Channel\Jobs\PullChannelOrdersJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\QueueCapacityReader;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;

class PullLiveOrdersCommand extends Command
{
    protected $signature = 'channel:pull-orders
        {--shop= : Batasi ke satu shop_id marketplace}
        {--from= : Awal jendela tarik (waktu WIB, mis. "2026-08-01" atau "2026-08-01 07:00")}
        {--to= : Akhir jendela tarik (waktu WIB)}
        {--hours=24 : Rentang lookback dalam jam jika --from tidak diisi}
        {--queue : Antrikan pull per toko ke worker terbatas}
        {--overlap-minutes=5 : Overlap aman dari sinkronisasi terakhir saat --queue}
        {--lease-seconds=420 : Durasi maksimum satu toko boleh memiliki pull aktif saat --queue}
        {--include-shadow : Sertakan juga toko berstatus shadow mode}
        {--recovery : Tarik rentang historis saat sinkronisasi global dijeda melalui queue recovery terpisah}
        {--dry-run : Jalankan jalur kode sebenarnya lalu rollback tanpa menyimpan}';

    protected $description = 'Tarik pesanan marketplace secara inkremental sebagai jaring pengaman webhook yang terlewat.';

    private const TIMEZONE = 'Asia/Jakarta';

    public function handle(
        ChannelShopRepository $shopRepository,
        ChannelOrderPullLeaseService $leases,
        QueueCapacityReader $capacity,
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        $isRecovery = (bool) $this->option('recovery');
        $settings = app(ChannelSyncSettingService::class);
        if ($isRecovery && ! (bool) $this->option('queue')) {
            $this->error('--recovery hanya dapat digunakan bersama --queue agar proses tetap terbatas dan dapat dilanjutkan.');

            return self::FAILURE;
        }

        if ($isRecovery && (! $this->option('from') || ! $this->option('to'))) {
            $this->error('--recovery wajib menggunakan --from dan --to untuk membatasi rentang historis.');

            return self::FAILURE;
        }

        if ($settings->isPaused() && ! $isRecovery) {
            $this->info('Sinkronisasi channel dijeda — pull order dibuang tanpa memanggil marketplace.');

            return self::SUCCESS;
        }
        $explicitFrom = $this->parseOption('from');
        $explicitTo = $this->parseOption('to');
        $hours = max(1, (int) $this->option('hours'));

        if ($explicitFrom && $explicitTo && $explicitFrom->greaterThanOrEqualTo($explicitTo)) {
            $this->error('Opsi --from harus lebih awal dari --to.');

            return self::FAILURE;
        }

        $runStartedAt = now();
        $shops = $this->resolveShops();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko aktif yang memenuhi kriteria.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN: perubahan akan di-rollback di akhir setiap toko.');
        }

        if ((bool) $this->option('queue')) {
            if ($isDryRun) {
                $this->error('--queue tidak dapat digabung dengan --dry-run.');

                return self::FAILURE;
            }

            return $this->queueShopPulls(
                $shops,
                $shopRepository,
                $leases,
                $capacity,
                $explicitFrom,
                $explicitTo,
                $hours,
                $isRecovery,
            );
        }

        $rows = [];
        $failed = 0;

        ChannelSyncSettingService::withInboundBypass(function () use ($shops, $shopRepository, $runStartedAt, $explicitFrom, $explicitTo, $hours, $isDryRun, &$rows, &$failed) {
            foreach ($shops as $shop) {
                $channelCode = $shop->channel->code ?? 'unknown';
                $windowEnd = $explicitTo ?: $runStartedAt->copy();
                $windowStart = $explicitFrom ?: $windowEnd->copy()->subHours($hours);
                $windowStart = app(ChannelSyncSettingService::class)->effectiveInboundStart($windowStart);

                $this->line("Menarik order {$shop->shop_name} ({$channelCode}) {$this->formatWindow($windowStart, $windowEnd)}");

                try {
                    $count = $this->pullWithinWindow($shop, $channelCode, $windowStart, $windowEnd, $isDryRun);
                } catch (UnsupportedShadowChannelException) {
                    $rows[] = [$shop->shop_name, $channelCode, '-', 'channel tidak didukung'];

                    continue;
                } catch (\Throwable $e) {
                    $failed++;
                    $rows[] = [$shop->shop_name, $channelCode, '-', 'GAGAL: '.$e->getMessage()];
                    $shopRepository->markOrderSyncProblem($shop->id, $e->getMessage());
                    report($e);

                    continue;
                }

                if (! $isDryRun && ! $explicitTo) {
                    $shopRepository->markOrderSyncOk($shop->id);
                }

                $rows[] = [$shop->shop_name, $channelCode, $count, $isDryRun ? 'dry run (rollback)' : 'ok'];
            }
        });

        $this->table(['Toko', 'Channel', 'Order', 'Status'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} toko gagal ditarik.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveShops()
    {
        $includeShadow = (bool) $this->option('include-shadow');

        return ChannelShop::with('channel')
            ->where('is_active', true)
            ->where('order_sync_enabled', true)
            ->whereNull('disconnected_at')
            ->when(! $includeShadow, fn ($q) => $q->where('is_shadow_mode', false))
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();
    }

    private function queueShopPulls(
        Collection $shops,
        ChannelShopRepository $shopRepository,
        ChannelOrderPullLeaseService $leases,
        QueueCapacityReader $capacity,
        ?Carbon $explicitFrom,
        ?Carbon $explicitTo,
        int $hours,
        bool $isRecovery = false,
    ): int {
        $routingKey = $isRecovery ? 'channel_order_recovery' : 'channel_sync';
        $routing = (array) config("queue.routing.{$routingKey}", []);
        $leaseSeconds = min(900, max(
            (int) ($routing['lease_seconds'] ?? 420),
            (int) $this->option('lease-seconds'),
        ));
        $windowMinutes = (int) ($routing['window_minutes'] ?? 10);
        $overlapMinutes = min(30, max(1, (int) $this->option('overlap-minutes')));
        $windowEnd = $explicitTo ?: now();
        $rows = [];
        $failed = 0;

        $maxDepth = $isRecovery
            ? (int) ($routing['max_depth'] ?? 8)
            : (int) config('queue.backpressure.channel_sync_max_depth', 24);
        $maxMemoryRatio = $isRecovery
            ? (float) ($routing['max_memory_ratio'] ?? 0.70)
            : (float) config('queue.backpressure.channel_sync_max_memory_ratio', 0.70);
        $queueConnection = (string) ($routing['connection'] ?? 'redis-channel-sync');
        $queueName = (string) ($routing['queue'] ?? 'channel-sync');
        $health = $capacity->inspect($queueConnection, $queueName);

        if ((bool) config('queue.backpressure.enabled', true)
            && (! $health['allowed']
                || $health['queue_depth'] >= $maxDepth
                || ($health['memory_ratio'] !== null && $health['memory_ratio'] >= $maxMemoryRatio))) {
            $reason = $health['error'] ?? sprintf(
                'depth=%d/%d, memory=%s/%s',
                $health['queue_depth'],
                $maxDepth,
                $health['memory_ratio'] !== null ? round($health['memory_ratio'] * 100, 1).'%' : 'n/a',
                round($maxMemoryRatio * 100, 1).'%'
            );
            $this->warn("Pull order ditunda oleh backpressure ({$reason}). Tidak ada job yang dihapus; scheduler akan mencoba lagi.");

            return self::SUCCESS;
        }

        $configuredParallelism = $isRecovery
            ? (int) ($routing['parallelism'] ?? 4)
            : $shops->count();
        $availableSlots = (bool) config('queue.backpressure.enabled', true)
            ? min($configuredParallelism, max(0, $maxDepth - $health['queue_depth']))
            : min($configuredParallelism, $shops->count());

        foreach ($shops as $shop) {
            if ($availableSlots < 1) {
                $rows[] = [$shop->shop_name, $shop->channel->code ?? 'unknown', '-', 'ditunda backpressure queue'];

                continue;
            }

            $hasPendingWindow = $shop->order_pull_window_from
                && $shop->order_pull_window_to
                && (
                    (! $isRecovery && ! $explicitFrom && ! $explicitTo)
                    || ($isRecovery
                        && $shop->order_sync_status === ChannelShop::ORDER_SYNC_PROBLEM
                        && (int) $shop->order_pull_attempts > 0
                        && $explicitFrom
                        && $explicitTo
                        && $shop->order_pull_window_from->greaterThanOrEqualTo($explicitFrom)
                        && $shop->order_pull_window_to->lessThanOrEqualTo($explicitTo))
                );

            $windowStart = $hasPendingWindow
                ? $shop->order_pull_window_from->copy()
                : ($explicitFrom
                    ?: ($shop->last_order_synced_at
                        ? $shop->last_order_synced_at->copy()->subMinutes($overlapMinutes)
                        : $windowEnd->copy()->subHours($hours)));
            if (! $isRecovery) {
                $windowStart = app(ChannelSyncSettingService::class)->effectiveInboundStart($windowStart);
            }

            $requestedWindowEnd = $hasPendingWindow
                ? $shop->order_pull_window_to->copy()
                : $windowEnd;

            $boundedWindowEnd = $windowStart->copy()->addMinutes($windowMinutes);
            $shopWindowEnd = $requestedWindowEnd->lessThan($boundedWindowEnd)
                ? $requestedWindowEnd
                : $boundedWindowEnd;

            if ($windowStart->greaterThanOrEqualTo($shopWindowEnd)) {
                $rows[] = [$shop->shop_name, $shop->channel->code ?? 'unknown', '-', 'window tidak valid'];

                continue;
            }

            $token = $leases->acquire(
                $shop,
                $leaseSeconds,
                $windowStart,
                $shopWindowEnd,
                $isRecovery
                    ? (int) ($routing['max_attempts'] ?? 3)
                    : (int) config('queue.routing.channel_sync.max_attempts', 8),
            );
            if ($token === null) {
                $maxAttempts = $isRecovery
                    ? (int) ($routing['max_attempts'] ?? 3)
                    : (int) config('queue.routing.channel_sync.max_attempts', 8);
                $status = (int) $shop->order_pull_attempts >= $maxAttempts
                    ? 'dikarantiina setelah batas retry'
                    : 'masih diproses';
                $rows[] = [$shop->shop_name, $shop->channel->code ?? 'unknown', '-', $status];

                continue;
            }

            try {
                PullChannelOrdersJob::dispatch(
                    (string) $shop->id,
                    $token,
                    $windowStart->toIso8601String(),
                    $shopWindowEnd->toIso8601String(),
                    (string) ($shop->channel->code ?? 'channel'),
                    $isRecovery,
                    $isRecovery ? $windowEnd->toIso8601String() : null,
                )->onQueue($queueName);

                $rows[] = [$shop->shop_name, $shop->channel->code ?? 'unknown', '-', 'diantrikan'];
                $availableSlots--;
            } catch (\Throwable $e) {
                $shopRepository->markScheduledOrderPullFailed((string) $shop->id, $token, $e->getMessage());
                $leases->release((string) $shop->id, $token);
                report($e);
                $rows[] = [$shop->shop_name, $shop->channel->code ?? 'unknown', '-', 'GAGAL enqueue: '.$e->getMessage()];
                $failed++;
            }
        }

        $this->table(['Toko', 'Channel', 'Order', 'Status'], $rows);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function pullWithinWindow(ChannelShop $shop, string $channelCode, Carbon $from, Carbon $to, bool $isDryRun): int
    {
        $pull = fn (): int => match ($channelCode) {
            'shopee' => app(ShopeeOrderService::class)->pullOrders($shop->shop_id, $from->timestamp, $to->timestamp),
            'tiktok' => app(TikTokOrderService::class)->pullOrders($shop->shop_id, $from->timestamp, $to->timestamp),
            'lazada' => app(LazadaOrderService::class)->pullOrders($shop->shop_id, $from->toIso8601String(), $to->toIso8601String()),
            default => throw new UnsupportedShadowChannelException($channelCode),
        };

        if (! $isDryRun) {
            return $pull();
        }

        DB::beginTransaction();

        try {
            return $pull();
        } finally {
            DB::rollBack();
        }
    }

    private function parseOption(string $name): ?Carbon
    {
        $value = $this->option($name);

        if (! $value) {
            return null;
        }

        return Carbon::parse($value, self::TIMEZONE)->setTimezone(config('app.timezone'));
    }

    private function formatWindow(Carbon $from, Carbon $to): string
    {
        $format = 'd/m H:i';

        return '['
            .$from->copy()->setTimezone(self::TIMEZONE)->format($format)
            .' - '
            .$to->copy()->setTimezone(self::TIMEZONE)->format($format)
            .' WIB]';
    }
}
