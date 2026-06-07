<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Warehouse;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Services\ChannelProductService;
use Modules\Channel\Jobs\ProcessTikTokWebhook;

class TestTikTokAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:test-tiktok-sync {--shop= : ID Channel Shop} {--scenario=all : Skenario (push, pull, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'AI Agent untuk mensimulasikan Create, Push, dan Pull Produk Real ke TikTok Shop';

    /**
     * Execute the console command.
     */
    public function handle(ChannelProductService $service)
    {
        $this->info('🤖 Memulai Agen AI Pengujian TikTok Sync...');

        $shopId = $this->option('shop');
        if (!$shopId) {
            $shop = ChannelShop::first();
            if (!$shop) {
                $this->error('❌ Tidak ada Channel Shop yang terdaftar. Buat shop terlebih dahulu.');
                return;
            }
            $shopId = $shop->id;
        }

        $scenario = $this->option('scenario');

        // 1. Setup Master Data jika kosong
        $category = DB::table('categories')->where('name', 'Elektronik & Gadget')->first();
        if (!$category) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Elektronik & Gadget',
                'description' => 'Kategori elektronik, smartphone, dll.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $categoryId = $category->id;
        }

        $brand = DB::table('brands')->where('name', 'Apple')->first();
        if (!$brand) {
            $brandId = DB::table('brands')->insertGetId([
                'name' => 'Apple',
                'description' => 'Apple Inc.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $brandId = $brand->id;
        }

        // 2. Data Produk Nyata
        $productData = [
            'name' => 'Apple iPhone 15 Pro Max 256GB - Titanium (Garansi Resmi iBox)',
            'sku' => 'IP15PM-256-NT',
            'description' => 'iPhone 15 Pro Max. Dirancang dengan titanium aerospace-grade, chip A17 Pro yang revolusioner, dan sistem kamera Pro yang paling canggih. Garansi Resmi iBox 1 Tahun.',
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'order_type' => 'REGULER',
            'weight' => 0.25, // 250 gram
            'length' => 16,
            'width' => 8,
            'height' => 1,
            'condition' => 'NEW',
            'is_cod_allowed' => true,
            'danger_level' => 0,
            'is_draft' => false,
            'is_active' => true,
            
            // Variants
            'variants' => [
                [
                    'sku' => 'IP15PM-256-NT-NATURAL',
                    'price' => 24999000,
                    'is_active' => true,
                    // Karena kita buat baru, stok mungkin 0, harus lewat inventori,
                    // Tapi untuk simplifikasi test kita bypass atau asumsikan stock 10
                ]
            ]
        ];

        if ($scenario === 'all' || $scenario === 'push') {
            $this->info("📦 [PUSH] Membuat Produk Lokal & Mendorong ke TikTok...");
            $this->info("👉 {$productData['name']} (SKU: {$productData['sku']})");

            try {
                $result = $service->createAndPushProduct($productData, $shopId);
                $this->info("✅ Sukses!");
                $this->line("Detail: " . json_encode($result, JSON_PRETTY_PRINT));
                
                $this->info("⏳ Job sinkronisasi dilempar ke queue 'channel_sync'.");
                $this->info("Jalankan `php artisan horizon` atau `php artisan queue:work --queue=channel_sync` untuk memprosesnya.");

            } catch (\Exception $e) {
                $this->error("❌ Gagal Push Produk: " . $e->getMessage());
            }
        }

        if ($scenario === 'all' || $scenario === 'pull') {
            $this->info("\n📥 [PULL] Mensimulasikan Pull Webhook dari TikTok...");
            
            // Dummy Payload seolah dari TikTok
            $dummyPayload = [
                'type' => 3, // Product update / stock change
                'shop_id' => '74958123985',
                'timestamp' => time(),
                'data' => [
                    'product_id' => '1729581958212', // ID eksternal dari tiktok
                    'status' => 4, // Live
                    'skus' => [
                        [
                            'id' => '1729581958212_SKU1',
                            'inventory' => [
                                [
                                    'quantity' => 5 // Stok berkurang di TikTok
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $this->info("👉 Mendispatch Webhook Job...");
            ProcessTikTokWebhook::dispatch($shopId, $dummyPayload);
            
            $this->info("✅ Webhook berhasil diproses secara asinkron!");
            $this->info("Job masuk ke queue 'tiktok_webhooks'.");
        }

        $this->info("\n🎉 Uji coba oleh Agen AI selesai!");
    }
}
