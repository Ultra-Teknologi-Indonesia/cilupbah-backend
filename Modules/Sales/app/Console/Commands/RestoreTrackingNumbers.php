<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Models\SalesOrder;

class RestoreTrackingNumbers extends Command
{
    protected $signature = 'orders:restore-tracking
        {--source=tiktok : Channel source (tiktok|shopee)}
        {--days=30 : Order yang di-update dalam N hari terakhir}
        {--dry : Tampilkan order yang affected tanpa re-pull}';

    protected $description = 'Restore tracking number yang hilang akibat bug re-sync (null overwrite)';

    public function handle(): int
    {
        $source = $this->option('source');
        $days = (int) $this->option('days');
        $dry = (bool) $this->option('dry');

        $orders = SalesOrder::query()
            ->whereNull('tracking_number')
            ->whereNotNull('channel_order_no')
            ->whereNotNull('channel_shop_id')
            ->where('source', $source)
            ->whereIn('status', ['shipped', 'packed', 'completed', 'delivered'])
            ->where('updated_at', '>=', now()->subDays($days))
            ->with('channelShop:id,shop_id')
            ->get(['id', 'salesorder_no', 'channel_order_no', 'channel_shop_id', 'status', 'source']);

        if ($orders->isEmpty()) {
            $this->info('Tidak ada order dengan tracking hilang.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$orders->count()} order {$source} tanpa tracking number:");
        $this->table(
            ['SO#', 'Channel Order', 'Status'],
            $orders->map(fn ($o) => [$o->salesorder_no, $o->channel_order_no, $o->status])
        );

        if ($dry) {
            $this->warn('Mode --dry, tidak melakukan re-pull.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Re-pull {$orders->count()} order dari {$source} API?")) {
            return self::SUCCESS;
        }

        $service = $source === 'tiktok'
            ? app(TikTokOrderService::class)
            : app(ShopeeOrderService::class);

        $restored = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $shopId = $order->channelShop?->shop_id;
            if (! $shopId) {
                $this->warn("  skip {$order->salesorder_no}: no shop_id");
                $failed++;
                continue;
            }

            try {
                $method = $source === 'tiktok' ? 'pullOrderById' : 'pullOrderById';
                $service->$method($shopId, $order->channel_order_no);

                $order->refresh();
                if ($order->tracking_number) {
                    $this->info("  ✓ {$order->salesorder_no} → {$order->tracking_number}");
                    $restored++;
                } else {
                    $this->warn("  ✗ {$order->salesorder_no} → masih kosong (API tidak return packages)");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ {$order->salesorder_no} → {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Selesai: {$restored} restored, {$failed} gagal/tetap kosong.");

        if ($failed > 0) {
            $this->warn('Order yang masih kosong bisa diisi manual via menu Pesanan → detail → input AWB.');
        }

        return self::SUCCESS;
    }
}
