<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Models\Category;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\AttributeOption;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class ProductCreateAgentCommand extends Command
{

    protected $signature = 'agent:product-create';

    protected $description = 'AI Agent untuk mensimulasikan Create Produk Lokal Realistis (Internal)';

    public function handle()
    {
        $this->info('🤖 Memulai Agen AI: Create Produk Lokal Realistis...');

        $category = Category::firstOrCreate(
            ['name' => 'Elektronik & Gadget'],
            ['description' => 'Kategori elektronik, smartphone, dll.', 'is_active' => true]
        );

        $brand = Brand::firstOrCreate(
            ['name' => 'Samsung'],
            ['description' => 'Samsung Electronics', 'is_active' => true]
        );

        $localAttr = Attribute::firstOrCreate(['name' => 'Warna', 'type' => 'spec']);
        $localOpt = AttributeOption::firstOrCreate(['attribute_id' => $localAttr->id, 'value' => 'Titanium Black']);

        $productData = [
            'name' => 'Samsung Galaxy S24 Ultra 512GB - Titanium Black (Garansi Resmi SEIN)',
            'sku' => 'S24U-512-TB',
            'description' => 'Samsung Galaxy S24 Ultra. Dilengkapi dengan Galaxy AI, bodi titanium yang kokoh, dan kamera 200MP paling mutakhir. Garansi Resmi SEIN 1 Tahun.',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'order_type' => 'REGULER',
            'weight' => 0.23, 
            'length' => 16.2,
            'width' => 7.9,
            'height' => 0.86,
            'condition' => 'NEW',
            'is_cod_allowed' => true,
            'danger_level' => 0,
            'is_draft' => false,
            'is_active' => true,

            'variants' => [
                [
                    'sku' => 'S24U-512-TB-VAR',
                    'sell_price' => 21999000,
                    'is_active' => true,
                ]
            ],

            'specifications' => [
                [
                    'attribute_id' => $localAttr->id,
                    'attribute_option_id' => $localOpt->id,
                ]
            ]
        ];

        $this->info("📦 [CREATE] Menyimpan Produk via API lokal (/api/v1/products)...");
        $this->info("👉 {$productData['name']} (SKU: {$productData['sku']})");

        try {
            $user = User::first();
            if (!$user) {
                $this->error("❌ Tidak ada User di database. Silakan jalankan seeder terlebih dahulu.");
                return;
            }

            $token = $user->createToken('AgentCreateToken')->plainTextToken;

            $response = Http::withToken($token)
                ->post(url('api/v1/products'), $productData);

            if ($response->failed()) {
                $this->error("❌ Gagal Membuat Produk via API: " . $response->body());
                return;
            }

            $productId = $response->json('data.product_id') ?? $response->json('data.id');

            $this->info("✅ Sukses Membuat Produk via API!");
            $this->line("======================================");
            $this->info("PRODUCT ID (UUID) : " . $productId);
            $this->line("======================================");
            $this->line("Gunakan ID ini untuk command Push ke TikTok:");
            $this->comment("php artisan agent:product-push --product=\"{$productId}\"");

        } catch (\Exception $e) {
            $this->error("❌ Terjadi Kesalahan: " . $e->getMessage());
        }
    }
}
