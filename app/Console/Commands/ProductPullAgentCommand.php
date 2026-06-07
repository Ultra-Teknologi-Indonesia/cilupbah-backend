<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Illuminate\Support\Str;

class ProductPullAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:product-pull {--shop= : ID Channel Shop} {--external_id= : ID Produk TikTok}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'AI Agent untuk mensimulasikan Pull Webhook dari TikTok Shop';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🤖 Memulai Agen AI: Pull Produk (Webhook) dari TikTok...');

        $shopId = $this->option('shop');
        if (!$shopId) {
            $shop = ChannelShop::first();
            if (!$shop) {
                $this->error('❌ Tidak ada Channel Shop yang terdaftar. Harap tambahkan shop terlebih dahulu.');
                return;
            }
            $shopId = $shop->id;
        } else {
            $shop = ChannelShop::find($shopId);
            if (!$shop) {
                $this->error("❌ Channel Shop dengan ID {$shopId} tidak ditemukan.");
                return;
            }
        }

        $externalId = $this->option('external_id');
        if (!$externalId) {
            // Generate dummy ID if not provided
            $externalId = '1729581958' . rand(100, 999);
            $this->warn("⚠️  Parameter --external_id tidak diisi, menggunakan ID Dummy TikTok: {$externalId}");
        }

        $this->info("\n📥 [PULL] Mengkonstruksi Payload Webhook Palsu (Simulasi)...");
        
        // Simulating a webhook payload from TikTok containing inventory and status updates
        $dummyPayload = [
            'type' => 3, // Product Status Change type in TikTok Webhook
            'shop_id' => $shop->channel_shop_id ?? '74958123985',
            'timestamp' => time(),
            'data' => [
                'product_id' => $externalId,
                'status' => 4, // 4 could mean Active/Live
                'skus' => [
                    [
                        'id' => $externalId . '_SKU1',
                        'inventory' => [
                            [
                                'quantity' => rand(10, 50) 
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $this->info("👉 Shop ID Target: {$shopId}");
        $this->info("👉 Product ID Webhook: {$externalId}");
        $this->line("👉 Mendispatch Webhook Job...");

        try {
            ProcessTikTokWebhook::dispatch($shopId, $dummyPayload);
            
            $this->info("✅ Webhook berhasil diproses secara asinkron!");
            $this->line("======================================");
            $this->info("Job masuk ke queue 'tiktok_webhooks'.");
            $this->info("Jalankan `php artisan queue:work --queue=tiktok_webhooks` atau `php artisan horizon`");
            $this->info("untuk melihat proses penarikan (pull) dari webhook.");
            $this->line("======================================");
        } catch (\Exception $e) {
            $this->error("❌ Gagal melemparkan Job Webhook: " . $e->getMessage());
        }
    }
}
