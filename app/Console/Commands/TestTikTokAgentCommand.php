<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Models\Category;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Product;
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
        $category = Category::firstOrCreate(
            ['name' => 'Elektronik & Gadget'],
            ['description' => 'Kategori elektronik, smartphone, dll.', 'is_active' => true]
        );

        $brand = Brand::firstOrCreate(
            ['name' => 'Apple'],
            ['description' => 'Apple Inc.', 'is_active' => true]
        );

        // 1.5. Simulasi Mapping Kategori & Atribut Omnichannel
        $this->info("🔄 Menyiapkan Simulasi Pemetaan (Mapping) Kategori & Atribut Omnichannel...");
        
        // Buat atau cari Channel Category (Misal ID TikTok: 839824)
        $channelCategory = \Modules\Product\Models\ChannelCategory::firstOrCreate([
            'channel_id' => $shop->channel_id,
            'external_id' => '839824'
        ], ['name' => 'Pakaian Dummy']);

        // Map kategori lokal ke kategori TikTok
        $category->channelCategories()->syncWithoutDetaching([$channelCategory->id]);

        // Setup Channel Attribute
        $channelAttr = \Modules\Product\Models\ChannelAttribute::updateOrCreate([
            'channel_category_id' => $channelCategory->id,
            'external_id' => '100393' // Atribut ID TikTok wajib
        ], ['name' => 'Bahan', 'is_required' => true]);

        // Setup Channel Attribute Option
        $channelOpt = \Modules\Product\Models\ChannelAttributeOption::updateOrCreate([
            'channel_attribute_id' => $channelAttr->id,
            'external_id' => '1001182' // Opsi ID TikTok
        ], ['name' => 'Polos']);

        // Setup Local Attribute & Option
        $localAttr = \Modules\Product\Models\Attribute::firstOrCreate(['name' => 'Material', 'type' => 'spec']);
        $localOpt = \Modules\Product\Models\AttributeOption::firstOrCreate(['attribute_id' => $localAttr->id, 'value' => 'Polos (Bebas)']);

        // Map Local -> Channel
        $localAttr->channelAttributes()->syncWithoutDetaching([$channelAttr->id]);
        $localOpt->channelAttributeOptions()->syncWithoutDetaching([$channelOpt->id]);

        // 2. Data Produk Nyata
        $productData = [
            'name' => 'Apple iPhone 15 Pro Max 256GB - Titanium (Garansi Resmi iBox)',
            'sku' => 'IP15PM-256-NT',
            'description' => 'iPhone 15 Pro Max. Dirancang dengan titanium aerospace-grade, chip A17 Pro yang revolusioner, dan sistem kamera Pro yang paling canggih. Garansi Resmi iBox 1 Tahun.',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
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
            ],

            // Specifications (Atribut Produk)
            'specifications' => [
                [
                    'attribute_id' => $localAttr->id,
                    'attribute_option_id' => $localOpt->id,
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
