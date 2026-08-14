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

class PullLiveOrdersCommand extends Command
{
    protected $signature = 'channel:pull-orders
        {--shop= : Batasi ke satu shop_id marketplace}
        {--from= : Awal jendela tarik (waktu WIB, mis. "2026-08-01" atau "2026-08-01 07:00")}
        {--to= : Akhir jendela tarik (waktu WIB)}
        {--hours=24 : Rentang lookback dalam jam jika --from tidak diisi}
        {--include-shadow : Sertakan juga toko berstatus shadow mode}
        {--dry-run : Jalankan jalur kode sebenarnya lalu rollback tanpa menyimpan}';

    protected $description = 'Tarik pesanan marketplace secara inkremental sebagai jaring pengaman webhook yang terlewat.';

    private const TIMEZONE = 'Asia/Jakarta';

    public function handle(ChannelShopRepository $shopRepository): int
    {
        $isDryRun = (bool) $this->option('dry-run');
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

        $rows = [];
        $failed = 0;

        ChannelSyncSettingService::withInboundBypass(function () use ($shops, $shopRepository, $runStartedAt, $explicitFrom, $explicitTo, $hours, $isDryRun, &$rows, &$failed) {
            foreach ($shops as $shop) {
                $channelCode = $shop->channel->code ?? 'unknown';
                $windowEnd = $explicitTo ?: $runStartedAt->copy();
                $windowStart = $explicitFrom ?: $windowEnd->copy()->subHours($hours);

                $this->line("Menarik order {$shop->shop_name} ({$channelCode}) {$this->formatWindow($windowStart, $windowEnd)}");

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
            ->when(! $includeShadow, fn ($q) => $q->where('is_shadow_mode', false))
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();
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
