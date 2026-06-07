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
use Illuminate\Support\Facades\Http;
use App\Models\User;

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

        $this->info("📦 [PUSH] Hit API Push Produk ke TikTok...");
        $this->info("👉 Target Produk : {$product->name} (SKU: {$product->sku})");
        $this->info("👉 Target Shop   : {$shop->name} (Platform: {$shop->channel->name})");

        try {
            $user = User::first();
            if (!$user) {
                $this->error("❌ Tidak ada User di database.");
                return;
            }

            $token = $user->createToken('AgentPushToken')->plainTextToken;

            $response = Http::withToken($token)->post(url('api/v1/tiktok/sync/products/push'), [
                'shop_id' => $shopId,
                'product_id' => $product->id,
            ]);

            if ($response->failed()) {
                $this->error("❌ Gagal Push Produk via API: " . $response->body());
                return;
            }
            
            $this->info("✅ API Push Berhasil Di-hit!");
            $this->line("======================================");
            $this->info("Response: " . json_encode($response->json(), JSON_PRETTY_PRINT));
            $this->line("======================================");

        } catch (\Exception $e) {
            $this->error("❌ Terjadi kesalahan: " . $e->getMessage());
        }
    }
}
