<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Models\Product;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ChannelCategory;
use Modules\Product\Models\ChannelAttribute;
use Modules\Product\Models\ChannelAttributeOption;
use Modules\Product\Models\Category;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\AttributeOption;
use Modules\Channel\Jobs\SyncProductToChannelJob;

class ProductPushAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:product-push {--product= : ID (UUID) Produk Lokal} {--shop= : ID Channel Shop}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'AI Agent untuk mendorong (push) Produk Lokal ke TikTok Shop';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🤖 Memulai Agen AI: Push Produk ke TikTok Shop...');

        $productId = $this->option('product');
        if (!$productId) {
            $this->error('❌ Parameter --product wajib diisi. (Contoh: --product="018...-...")');
            $this->line('Gunakan `php artisan agent:product-create` untuk mendapatkan ID produk baru.');
            return;
        }

        $product = Product::with(['category', 'specifications.attribute', 'specifications.attributeOption'])->find($productId);
        if (!$product) {
            $this->error("❌ Produk dengan ID {$productId} tidak ditemukan di database lokal.");
            return;
        }

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

        $this->info("🔄 Memastikan Mapping Kategori dan Atribut sudah dikonfigurasi...");

        // Dummy TikTok Category Mapping (Smartphone)
        $channelCategory = ChannelCategory::firstOrCreate([
            'channel_id' => $shop->channel_id,
            'external_id' => '839824' // Dummy TikTok ID for Electronics/Smartphones
        ], ['name' => 'Smartphone Dummy TikTok']);

        $product->category->channelCategories()->syncWithoutDetaching([$channelCategory->id]);

        // Dummy TikTok Attribute Mapping (Color)
        $channelAttr = ChannelAttribute::updateOrCreate([
            'channel_category_id' => $channelCategory->id,
            'external_id' => '100393' 
        ], ['name' => 'Warna', 'is_required' => true]);

        $channelOpt = ChannelAttributeOption::updateOrCreate([
            'channel_attribute_id' => $channelAttr->id,
            'external_id' => '1001182' 
        ], ['name' => 'Titanium Black']);

        // Find local attributes inside product specifications and map them
        foreach ($product->specifications as $spec) {
            if ($spec->attribute && $spec->attribute->name === 'Warna') {
                $spec->attribute->channelAttributes()->syncWithoutDetaching([$channelAttr->id]);
            }
            if ($spec->attributeOption && $spec->attributeOption->value === 'Titanium Black') {
                $spec->attributeOption->channelAttributeOptions()->syncWithoutDetaching([$channelOpt->id]);
            }
        }

        $this->info("📦 [PUSH] Melemparkan Job Sinkronisasi ke Queue...");
        $this->info("👉 Target Produk : {$product->name} (SKU: {$product->sku})");
        $this->info("👉 Target Shop   : {$shop->name} (Platform: {$shop->channel->name})");

        try {
            SyncProductToChannelJob::dispatch($product->id, $shopId, 'push');
            
            $this->info("✅ Job berhasil ditambahkan ke antrean (Queue)!");
            $this->line("======================================");
            $this->info("Jalankan `php artisan queue:work --queue=channel_sync` atau `php artisan horizon`");
            $this->info("untuk memproses push secara real-time ke TikTok API.");
            $this->line("======================================");

        } catch (\Exception $e) {
            $this->error("❌ Gagal melemparkan Job: " . $e->getMessage());
        }
    }
}
