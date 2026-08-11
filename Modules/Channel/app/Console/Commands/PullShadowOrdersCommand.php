<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\LazadaOrderService;
use Illuminate\Support\Str;

class PullShadowOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'channel:pull-shadow-orders {--dry-run : Lakukan dry run tanpa menyimpan ke DB}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menarik order dari marketplace untuk toko yang menggunakan Shadow Mode (walaupun sync_enabled global mati).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info("🚀 Memulai proses Pull Shadow Orders...");
        
        if ($isDryRun) {
            $this->warn("⚠️ DRY RUN MODE AKTIF - Data tidak akan disimpan ke Database!");
        }

        // 1. Bypass blokir sync_enabled global agar API tetap bisa dipanggil
        app()->bind(ChannelSyncSettingService::class, function () {
            return new class extends ChannelSyncSettingService {
                public function isPaused(): bool { return false; }
                public function isEnabled(): bool { return true; }
            };
        });

        // 2. Jika Dry Run, mock SalesOrderService agar tidak menyentuh DB
        if ($isDryRun) {
            app()->instance(SalesOrderService::class, new class extends SalesOrderService {
                public function __construct() {}
                public function upsertFromChannel(array $orderData, string $defaultStatus = 'pending', ?string $mappedStatus = null): ?string {
                    $no = $orderData['salesorder_no'] ?? $orderData['channel_order_no'] ?? 'UNKNOWN';
                    echo "   ✅ [DRY RUN] Order ditarik & diparsing: {$no} (Status: {$defaultStatus})\n";
                    return Str::uuid()->toString();
                }
            });
        }

        $startTimeWib = now('Asia/Jakarta')->startOfDay();
        $unixTimeFrom = $startTimeWib->timestamp;
        $isoTimeFrom  = $startTimeWib->setTimezone('UTC')->toIso8601String();

        // Cari toko yang aktif dan shadow mode aktif
        $shops = ChannelShop::with('channel')
            ->where('is_active', true)
            ->where('is_shadow_mode', true)
            ->get();

        if ($shops->isEmpty()) {
            $this->info("ℹ️ Tidak ada toko aktif dengan mode Shadow Mode.");
            return;
        }

        foreach ($shops as $shop) {
            $channelCode = $shop->channel->code ?? 'unknown';
            $this->info("\n🛒 Memproses Toko: {$shop->shop_name} ({$channelCode})");

            try {
                $count = 0;
                switch ($channelCode) {
                    case 'shopee':
                        $svc = app(ShopeeOrderService::class);
                        $count = $svc->pullOrders($shop->shop_id, $unixTimeFrom);
                        break;
                        
                    case 'tiktok':
                        $svc = app(TikTokOrderService::class);
                        $count = $svc->pullOrders($shop->shop_id);
                        break;
                        
                    case 'lazada':
                        $svc = app(LazadaOrderService::class);
                        $count = $svc->pullOrders($shop->shop_id, $isoTimeFrom);
                        break;
                        
                    default:
                        $this->warn("   ⚠️ Channel {$channelCode} tidak didukung.");
                        continue 2;
                }
                
                $this->info("   📊 Selesai! Total order ditarik: {$count}");
            } catch (\Throwable $e) {
                $this->error("   ❌ ERROR pada toko {$shop->shop_name}: " . $e->getMessage());
            }
        }

        $this->info("\n✅ Proses Pull Shadow Orders selesai.");
    }
}
