<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Exceptions\UnsupportedShadowChannelException;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;

class PullShadowOrdersCommand extends Command
{
    protected $signature = 'channel:pull-shadow-orders
        {--shop= : Batasi ke satu shop_id marketplace}
        {--from= : Awal jendela tarik (waktu WIB, mis. "2026-08-01" atau "2026-08-01 07:00")}
        {--to= : Akhir jendela tarik (waktu WIB). Kalau diisi, cursor tidak dimajukan}
        {--full : Abaikan cursor, tarik ulang sejak cutoff shadow toko}
        {--dry-run : Jalankan jalur kode sebenarnya lalu rollback, tanpa menyimpan}';

    protected $description = 'Menarik order marketplace untuk toko Shadow Mode secara inkremental (walaupun sync global mati).';

    public const OVERLAP_MINUTES = 30;

    private const DEFAULT_LOOKBACK_DAYS = 7;

    private const BACKFILL_LOOKBACK_DAYS = 30;

    private const TIMEZONE = 'Asia/Jakarta';

    public function handle(ChannelShopRepository $shopRepository): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $explicitFrom = $this->parseOption('from');
        $explicitTo = $this->parseOption('to');
        $isFull = (bool) $this->option('full');

        if ($explicitFrom && $explicitTo && $explicitFrom->greaterThanOrEqualTo($explicitTo)) {
            $this->error('Opsi --from harus lebih awal dari --to.');

            return self::FAILURE;
        }

        $runStartedAt = now();
        $shops = $this->resolveShops();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko aktif dengan Shadow Mode.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN: perubahan akan di-rollback di akhir setiap toko.');
        }

        $rows = [];
        $failed = 0;

        ChannelSyncSettingService::withInboundBypass(function () use ($shops, $shopRepository, $runStartedAt, $explicitFrom, $explicitTo, $isFull, $isDryRun, &$rows, &$failed) {
            foreach ($shops as $shop) {
                $channelCode = $shop->channel->code ?? 'unknown';
                $windowEnd = $explicitTo ?: $runStartedAt->copy();
                $windowStart = $explicitFrom ?: $this->resolveWindowStart($shop, $windowEnd, $isFull);

                if ($shop->shadow_started_at && $windowStart->lessThan($shop->shadow_started_at)) {
                    $this->warn("   Jendela dipangkas ke cutoff toko ({$shop->shadow_started_at->setTimezone(self::TIMEZONE)->format('d/m/Y H:i')} WIB).");
                    $windowStart = $shop->shadow_started_at->copy();
                }

                if ($windowStart->greaterThanOrEqualTo($windowEnd)) {
                    $rows[] = [$shop->shop_name, $channelCode, '-', 'dilewati (jendela kosong)'];
                    continue;
                }

                $this->line("Menarik {$shop->shop_name} ({$channelCode}) {$this->formatWindow($windowStart, $windowEnd)}");

                try {
                    $count = $this->pullWithinWindow($shop, $channelCode, $windowStart, $windowEnd, $isDryRun);
                } catch (UnsupportedShadowChannelException) {
                    $rows[] = [$shop->shop_name, $channelCode, '-', 'channel tidak didukung'];
                    continue;
                } catch (\Throwable $e) {
                    $failed++;
                    $rows[] = [$shop->shop_name, $channelCode, '-', 'GAGAL: ' . $e->getMessage()];
                    $shopRepository->markOrderSyncProblem($shop->id, $e->getMessage());
                    report($e);
                    continue;
                }

                $canAdvanceCursor = ! $isDryRun && ! $explicitTo;

                if ($canAdvanceCursor) {
                    $shopRepository->markShadowPulledUpTo($shop->id, $windowEnd);
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
        return ChannelShop::with('channel')
            ->where('is_active', true)
            ->where('is_shadow_mode', true)
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();
    }

    private function resolveWindowStart(ChannelShop $shop, Carbon $windowEnd, bool $isFull): Carbon
    {
        if ($isFull) {
            return $shop->shadow_started_at?->copy()
                ?: $windowEnd->copy()->subDays(self::BACKFILL_LOOKBACK_DAYS);
        }

        if ($shop->shadow_last_pulled_at) {
            return $shop->shadow_last_pulled_at->copy()->subMinutes(self::OVERLAP_MINUTES);
        }

        return $shop->shadow_started_at?->copy()
            ?: $windowEnd->copy()->subDays(self::DEFAULT_LOOKBACK_DAYS);
    }

    private function pullWithinWindow(ChannelShop $shop, string $channelCode, Carbon $from, Carbon $to, bool $isDryRun): int
    {
        $pull = fn (): int => match ($channelCode) {
            'shopee' => app(ShopeeOrderService::class)->pullOrders($shop->shop_id, $from->timestamp, $to->timestamp),
            'tiktok' => app(TikTokOrderService::class)->pullOrders($shop->shop_id, $from->timestamp, $to->timestamp),
            'lazada' => app(LazadaOrderService::class)->pullOrders($shop->shop_id, $from->toIso8601String(), $to->toIso8601String()),
            default  => throw new UnsupportedShadowChannelException($channelCode),
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
            . $from->copy()->setTimezone(self::TIMEZONE)->format($format)
            . ' - '
            . $to->copy()->setTimezone(self::TIMEZONE)->format($format)
            . ' WIB]';
    }
}
