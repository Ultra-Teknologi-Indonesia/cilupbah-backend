<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariationType;
use Modules\Product\Models\VariantOption;

/**
 * Seeds 30+ master products across the existing custom "Handphone & Aksesoris"
 * leaf categories (resolved by name — NOT created here). Covers every product
 * type (single / multi-variant / bundle / consignment / preorder / serial / COD),
 * each with real images uploaded to the internal S3 (Cloudflare R2) bucket.
 *
 * Idempotent: products keyed by SKU, media skipped when already present.
 */
class ProductCatalogSeeder extends Seeder
{
    /** Leaf category name => short SKU code + image search keyword. */
    private const CATEGORIES = [
        'Adaptor + Kabel'  => ['ADP', 'usb cable charger'],
        'Anti Crack Case'  => ['ACC', 'clear phone case'],
        'Hard Case'        => ['HRD', 'phone hard case'],
        'Leather Case'     => ['LTR', 'leather phone case'],
        'Skin Handphone'   => ['SKN', 'phone skin sticker'],
        'Soft Case'        => ['SFT', 'silicone phone case'],
        'Gantungan HP'     => ['GTG', 'phone strap charm'],
        'Screen Guard'     => ['SCG', 'screen protector glass'],
        'Strap'            => ['STR', 'smartwatch band strap'],
        'Lazypod'          => ['LZP', 'flexible phone tripod'],
        'Phone Holder'     => ['HLD', 'car phone holder'],
    ];

    private array $categoryIds = [];      // name => id
    private array $imageBytesCache = [];   // keyword => raw bytes
    private array $attributeIds = [];      // attr name => id
    private array $firstVariantByCat = []; // cat name => first variant id (for bundle components)

    private int $seq = 0;

    public function run(): void
    {
        $this->command->info('ProductCatalogSeeder: resolving categories...');
        $this->resolveCategories();
        $this->resolveAttributes();

        $created = 0;
        foreach ($this->catalog() as $entry) {
            if ($entry['archetype'] === 'bundle') {
                continue; // bundles handled after components exist
            }
            $this->createProduct($entry);
            $created++;
        }

        foreach ($this->catalog() as $entry) {
            if ($entry['archetype'] !== 'bundle') {
                continue;
            }
            $this->createProduct($entry);
            $created++;
        }

        $this->command->info("ProductCatalogSeeder: {$created} products ensured.");
        $this->command->table(
            ['Table', 'Count'],
            [
                ['products', Product::count()],
                ['product_variants', ProductVariant::count()],
                ['product_media', ProductMedia::count()],
                ['product_bundle_items', ProductBundleItem::count()],
            ]
        );
    }

    private function resolveCategories(): void
    {
        foreach (array_keys(self::CATEGORIES) as $name) {
            $cat = Category::query()
                ->where('name', $name)
                ->where('source', 'custom')
                ->where('is_leaf', true)
                ->first();

            if (! $cat) {
                throw new \RuntimeException(
                    "ProductCatalogSeeder: leaf category '{$name}' (source=custom) not found. "
                    . 'Categories must already exist in the BE — aborting.'
                );
            }

            $this->categoryIds[$name] = $cat->id;
        }
    }

    private function resolveAttributes(): void
    {
        foreach (['Warna', 'Ukuran', 'Tipe HP'] as $name) {
            $id = DB::table('attributes')->where('name', $name)->where('type', 'sales')->value('id');
            if (! $id) {
                $id = DB::table('attributes')->insertGetId([
                    'name' => $name,
                    'type' => 'sales',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->attributeIds[$name] = $id;
        }
    }

    private function brandId(string $name): int
    {
        return Brand::firstOrCreate(['name' => $name])->id;
    }

    private function createProduct(array $e): void
    {
        $catName = $e['cat'];
        [$code] = self::CATEGORIES[$catName];
        $this->seq++;
        $sku = sprintf('CLP-%s-%03d', $code, $this->seq);

        $existing = Product::where('sku', $sku)->first();
        if ($existing) {
            $this->command->line("  skip (exists): {$sku} {$e['name']}");
            return;
        }

        $isBundle = $e['archetype'] === 'bundle';
        $isConsignment = $e['archetype'] === 'consignment';
        $isPreorder = $e['archetype'] === 'preorder';

        $product = Product::create([
            'category_id' => $this->categoryIds[$catName],
            'brand_id' => $this->brandId($e['brand']),
            'name' => $e['name'],
            'sku' => $sku,
            'description' => $e['desc'] ?? ($e['name'] . ' — produk aksesoris berkualitas untuk perangkat Anda.'),
            'search_keyword' => Str::lower(str_replace(' ', ',', $e['name'])),
            'order_type' => $isPreorder ? 'PREORDER' : 'REGULER',
            'indent_days' => $isPreorder ? 7 : null,
            'weight' => $e['weight'] ?? 80,
            'weight_unit' => 'g',
            'length' => $e['dims'][0] ?? 18,
            'width' => $e['dims'][1] ?? 10,
            'height' => $e['dims'][2] ?? 3,
            'condition' => 'NEW',
            'is_cod_allowed' => $e['cod'] ?? false,
            'danger_level' => 0,
            'is_draft' => false,
            'is_active' => true,
            'status' => Product::STATUS_MASTER,
            'verified_at' => now(),
            'is_bundle' => $isBundle,
            'is_consignment' => $isConsignment,
            'is_stored' => ! $isBundle,
            'is_sold' => true,
            'is_purchased' => ! $isBundle,
            'purchase_lead_time' => $isPreorder ? 7 : 2,
            'package_contents' => $e['package'] ?? null,
        ]);

        // ---- Variants ----
        $variantIds = [];
        if ($isBundle) {
            $variant = $this->makeVariant($product, $sku, $e, null);
            $variantIds[] = $variant->id;
            $this->attachBundleItems($product, $catName, $e);
        } elseif (! empty($e['variation'])) {
            $variantIds = $this->makeVariantMatrix($product, $sku, $e);
        } else {
            $variant = $this->makeVariant($product, $sku, $e, null);
            $variantIds[] = $variant->id;
        }

        if (! isset($this->firstVariantByCat[$catName])) {
            $this->firstVariantByCat[$catName] = $variantIds[0];
        }

        // ---- Media (S3) ----
        $this->attachMedia($product, $catName, $sku, $variantIds, ! empty($e['variation']));

        $this->command->line("  + {$sku} [{$e['archetype']}] {$e['name']} (" . count($variantIds) . ' var)');
    }

    private function makeVariant(Product $product, string $baseSku, array $e, ?array $opts): ProductVariant
    {
        $suffix = $opts['suffix'] ?? '';
        $sku = $baseSku . ($suffix !== '' ? '-' . $suffix : '');
        $buy = $e['buy'];
        $sell = $e['sell'] ?? (int) round($buy * (1 + ($e['margin'] ?? 0.45)));
        $isSerial = $e['archetype'] === 'serial';

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'barcode' => $this->barcode($sku),
            'buy_price' => $buy,
            'sell_price' => $sell,
            'weight' => $e['weight'] ?? 80,
            'length' => $e['dims'][0] ?? 18,
            'width' => $e['dims'][1] ?? 10,
            'height' => $e['dims'][2] ?? 3,
            'is_serial_batch' => $isSerial,
            'is_active' => true,
            'min_stock' => 5,
            'safe_stock' => 10,
            'sequence_item' => 1,
        ]);
    }

    /** Build cartesian variants from one or two variation axes. */
    private function makeVariantMatrix(Product $product, string $baseSku, array $e): array
    {
        $axes = $e['variation']; // list of ['attr'=>name,'options'=>[...]]
        foreach ($axes as $axis) {
            ProductVariationType::firstOrCreate([
                'product_id' => $product->id,
                'attribute_id' => $this->attributeIds[$axis['attr']],
            ], ['sort_order' => 0]);
        }

        // cartesian product
        $combos = [[]];
        foreach ($axes as $axis) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($axis['options'] as $opt) {
                    $next[] = array_merge($combo, [[$axis['attr'], $opt]]);
                }
            }
            $combos = $next;
        }

        $variantIds = [];
        $i = 0;
        foreach ($combos as $combo) {
            $i++;
            $suffix = strtoupper(implode('-', array_map(
                fn ($p) => Str::slug($p[1], ''),
                $combo
            )));
            $variant = $this->makeVariant($product, $baseSku, $e, ['suffix' => $suffix]);
            foreach ($combo as [$attr, $value]) {
                VariantOption::create([
                    'variant_id' => $variant->id,
                    'attribute_id' => $this->attributeIds[$attr],
                    'value' => $value,
                ]);
            }
            $variantIds[] = $variant->id;
        }

        return $variantIds;
    }

    private function attachBundleItems(Product $bundle, string $catName, array $e): void
    {
        $componentCats = $e['components'] ?? [];
        foreach ($componentCats as $cName) {
            $variantId = $this->firstVariantByCat[$cName] ?? null;
            if (! $variantId) {
                continue;
            }
            ProductBundleItem::firstOrCreate([
                'bundle_product_id' => $bundle->id,
                'component_variant_id' => $variantId,
            ], ['qty' => 1]);
        }
    }

    private function attachMedia(Product $product, string $catName, string $sku, array $variantIds, bool $perVariant): void
    {
        if ($product->media()->exists()) {
            return;
        }

        [, $keyword] = self::CATEGORIES[$catName];

        // Product-level: primary + 1 gallery
        $primary = $this->imageFor($keyword, crc32($sku) % 90 + 1, $product->name);
        $this->storeMedia($product->id, null, "products/seed/{$sku}/main.jpg", $primary, 0, true);

        $gallery = $this->imageFor($keyword, crc32($sku . 'g') % 90 + 100, $product->name);
        $this->storeMedia($product->id, null, "products/seed/{$sku}/gallery.jpg", $gallery, 1, false);

        // Per-variant image for variant-type products
        if ($perVariant) {
            $n = 0;
            foreach ($variantIds as $vid) {
                $n++;
                $vSku = ProductVariant::whereKey($vid)->value('sku');
                $bytes = $this->imageFor($keyword, crc32($vSku) % 90 + 200, $product->name);
                $this->storeMedia($product->id, $vid, "products/seed/{$sku}/v{$n}.jpg", $bytes, $n + 1, false);
            }
        }
    }

    private function storeMedia(string $productId, ?string $variantId, string $path, string $bytes, int $sort, bool $primary): void
    {
        Storage::disk('s3')->put($path, $bytes, ['ContentType' => 'image/jpeg']);
        $url = Storage::disk('s3')->url($path);

        ProductMedia::create([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'media_type' => 'image',
            'url' => $url,
            'sort_order' => $sort,
            'is_primary' => $primary,
        ]);
    }

    /** Fetch a real-ish photo for the keyword (cached per keyword), fallback to a labelled placeholder. */
    private function imageFor(string $keyword, int $lock, string $label): string
    {
        $cacheKey = $keyword . ':' . $lock;
        if (isset($this->imageBytesCache[$cacheKey])) {
            return $this->imageBytesCache[$cacheKey];
        }

        $url = 'https://loremflickr.com/640/640/' . rawurlencode(str_replace(' ', ',', $keyword)) . '?lock=' . $lock;
        $ctx = stream_context_create(['http' => ['timeout' => 12], 'https' => ['timeout' => 12]]);
        $bytes = @file_get_contents($url, false, $ctx);

        if (! $bytes || strlen($bytes) < 1500) {
            $bytes = $this->placeholder($label);
        }

        return $this->imageBytesCache[$cacheKey] = $bytes;
    }

    private function placeholder(string $label): string
    {
        $img = imagecreatetruecolor(640, 640);
        $bg = imagecolorallocate($img, 30 + (crc32($label) % 120), 60, 120);
        $fg = imagecolorallocate($img, 245, 245, 245);
        imagefilledrectangle($img, 0, 0, 640, 640, $bg);
        $text = wordwrap($label, 22, "\n", true);
        $y = 280;
        foreach (explode("\n", $text) as $line) {
            imagestring($img, 5, 40, $y, $line, $fg);
            $y += 24;
        }
        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function barcode(string $sku): string
    {
        return '899' . substr((string) abs(crc32($sku)), 0, 10);
    }

    /**
     * The full catalog (33 products). archetype drives type-specific behaviour.
     */
    private function catalog(): array
    {
        $colors4 = ['Hitam', 'Putih', 'Navy', 'Merah'];
        $colors3 = ['Hitam', 'Bening', 'Pink'];
        $sizes = ['S', 'M', 'L'];

        return [
            // --- Adaptor + Kabel ---
            ['cat' => 'Adaptor + Kabel', 'name' => 'Adaptor Fast Charge GaN 65W', 'brand' => 'ANKER', 'archetype' => 'serial', 'buy' => 145000, 'margin' => 0.5, 'cod' => true, 'weight' => 120, 'desc' => 'Charger GaN 65W bergaransi resmi, dilacak per serial number.'],
            ['cat' => 'Adaptor + Kabel', 'name' => 'Kabel USB-C to USB-C 100W 1.5m', 'brand' => 'ACOME', 'archetype' => 'variant', 'buy' => 38000, 'margin' => 0.6, 'cod' => true, 'weight' => 60, 'variation' => [['attr' => 'Warna', 'options' => ['Hitam', 'Putih']]]],
            ['cat' => 'Adaptor + Kabel', 'name' => 'Kabel Lightning Braided 1m', 'brand' => 'ACOME', 'archetype' => 'single', 'buy' => 29000, 'margin' => 0.65, 'weight' => 45],

            // --- Anti Crack Case ---
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Bening iPhone 15', 'brand' => 'Apple', 'archetype' => 'single', 'buy' => 18000, 'margin' => 0.8, 'cod' => true, 'weight' => 40],
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Samsung S24', 'brand' => 'ADVAN', 'archetype' => 'single', 'buy' => 17000, 'margin' => 0.8, 'weight' => 42],
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Premium Acrylic', 'brand' => 'ADVAN', 'archetype' => 'variant', 'buy' => 22000, 'margin' => 0.85, 'variation' => [['attr' => 'Tipe HP', 'options' => ['iPhone 15', 'iPhone 14', 'S24']]]],

            // --- Hard Case ---
            ['cat' => 'Hard Case', 'name' => 'Hard Case Matte Custom Print', 'brand' => 'ALDO', 'archetype' => 'preorder', 'buy' => 25000, 'margin' => 1.0, 'weight' => 55, 'desc' => 'Hard case cetak custom sesuai pesanan, indikasi 7 hari kerja.'],
            ['cat' => 'Hard Case', 'name' => 'Hard Case Slim Frosted', 'brand' => 'ALDO', 'archetype' => 'variant', 'buy' => 19000, 'margin' => 0.9, 'cod' => true, 'variation' => [['attr' => 'Warna', 'options' => $colors4]]],
            ['cat' => 'Hard Case', 'name' => 'Hard Case Armor Shockproof', 'brand' => 'ARASHI', 'archetype' => 'single', 'buy' => 31000, 'margin' => 0.85, 'cod' => true, 'weight' => 70],

            // --- Leather Case ---
            ['cat' => 'Leather Case', 'name' => 'Leather Case Premium Sapi Asli', 'brand' => 'ALDO', 'archetype' => 'consignment', 'buy' => 95000, 'margin' => 0.7, 'weight' => 75, 'desc' => 'Kulit sapi asli, titipan mitra (konsinyasi).'],
            ['cat' => 'Leather Case', 'name' => 'Leather Flip Wallet Case', 'brand' => 'ALDO', 'archetype' => 'variant', 'buy' => 48000, 'margin' => 0.75, 'variation' => [['attr' => 'Warna', 'options' => ['Cokelat', 'Hitam']]]],
            ['cat' => 'Leather Case', 'name' => 'Leather Case Magnetic Slim', 'brand' => 'ARASHI', 'archetype' => 'single', 'buy' => 52000, 'margin' => 0.7, 'weight' => 60],

            // --- Skin Handphone ---
            ['cat' => 'Skin Handphone', 'name' => 'Skin Carbon 3M Custom', 'brand' => '3M', 'archetype' => 'preorder', 'buy' => 15000, 'margin' => 1.2, 'weight' => 20],
            ['cat' => 'Skin Handphone', 'name' => 'Skin Garskin Motif Marble', 'brand' => '3M', 'archetype' => 'variant', 'buy' => 12000, 'margin' => 1.3, 'variation' => [['attr' => 'Warna', 'options' => ['Marble Putih', 'Marble Hitam', 'Wood']]]],
            ['cat' => 'Skin Handphone', 'name' => 'Skin Matte Anti Gores Belakang', 'brand' => '3M', 'archetype' => 'single', 'buy' => 10000, 'margin' => 1.4, 'weight' => 15],

            // --- Soft Case ---
            ['cat' => 'Soft Case', 'name' => 'Soft Case Silikon Premium', 'brand' => 'ADVAN', 'archetype' => 'variant', 'buy' => 14000, 'margin' => 1.0, 'cod' => true, 'variation' => [['attr' => 'Warna', 'options' => $colors4], ['attr' => 'Ukuran', 'options' => $sizes]]],
            ['cat' => 'Soft Case', 'name' => 'Soft Case Jelly Bening', 'brand' => 'ADVAN', 'archetype' => 'single', 'buy' => 9000, 'margin' => 1.1, 'cod' => true, 'weight' => 30],
            ['cat' => 'Soft Case', 'name' => 'Soft Case Macaron Pastel', 'brand' => 'ADVAN', 'archetype' => 'variant', 'buy' => 11000, 'margin' => 1.1, 'variation' => [['attr' => 'Warna', 'options' => $colors3]]],

            // --- Gantungan HP ---
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Tali Manik Beads', 'brand' => 'AILITE', 'archetype' => 'variant', 'buy' => 13000, 'margin' => 1.2, 'variation' => [['attr' => 'Warna', 'options' => $colors3]]],
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Lanyard Polos', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 8000, 'margin' => 1.3, 'cod' => true, 'weight' => 25],
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Crossbody Strap', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 24000, 'margin' => 0.9, 'weight' => 50],

            // --- Screen Guard ---
            ['cat' => 'Screen Guard', 'name' => 'Tempered Glass Anti Gores 9H', 'brand' => 'ANKER', 'archetype' => 'variant', 'buy' => 7000, 'margin' => 1.5, 'cod' => true, 'variation' => [['attr' => 'Tipe HP', 'options' => ['iPhone 15', 'iPhone 14', 'S24', 'A55']]]],
            ['cat' => 'Screen Guard', 'name' => 'Anti Spy Privacy Glass', 'brand' => 'ANKER', 'archetype' => 'single', 'buy' => 12000, 'margin' => 1.4, 'weight' => 18],
            ['cat' => 'Screen Guard', 'name' => 'Hydrogel Film Full Cover', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 6000, 'margin' => 1.6, 'weight' => 12],

            // --- Strap (Smartwatch) ---
            ['cat' => 'Strap', 'name' => 'Strap Silikon Apple Watch', 'brand' => 'Apple', 'archetype' => 'variant', 'buy' => 22000, 'margin' => 1.0, 'cod' => true, 'variation' => [['attr' => 'Warna', 'options' => $colors4], ['attr' => 'Ukuran', 'options' => ['41mm', '45mm']]]],
            ['cat' => 'Strap', 'name' => 'Strap Kulit Premium Watch', 'brand' => 'ALDO', 'archetype' => 'consignment', 'buy' => 65000, 'margin' => 0.7, 'weight' => 35],
            ['cat' => 'Strap', 'name' => 'Strap Nylon Sport Loop', 'brand' => 'AILITE', 'archetype' => 'variant', 'buy' => 18000, 'margin' => 1.1, 'variation' => [['attr' => 'Warna', 'options' => $colors3]]],

            // --- Lazypod ---
            ['cat' => 'Lazypod', 'name' => 'Lazypod Flexible Premium Alumunium', 'brand' => 'AILITE', 'archetype' => 'serial', 'buy' => 55000, 'margin' => 0.8, 'weight' => 220, 'desc' => 'Lazypod aluminium premium bergaransi, dilacak per serial.'],
            ['cat' => 'Lazypod', 'name' => 'Lazypod Gurita Mini Tripod', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 28000, 'margin' => 0.9, 'cod' => true, 'weight' => 150],
            ['cat' => 'Lazypod', 'name' => 'Lazypod Clamp Meja Panjang', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 42000, 'margin' => 0.85, 'weight' => 300],

            // --- Phone Holder ---
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Mobil Magnetic', 'brand' => 'ANKER', 'archetype' => 'single', 'buy' => 35000, 'margin' => 0.9, 'cod' => true, 'weight' => 110],
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Motor Anti Getar', 'brand' => 'AILITE', 'archetype' => 'variant', 'buy' => 48000, 'margin' => 0.85, 'variation' => [['attr' => 'Warna', 'options' => ['Hitam', 'Merah']]]],
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Meja Adjustable', 'brand' => 'AILITE', 'archetype' => 'single', 'buy' => 26000, 'margin' => 0.95, 'weight' => 130],

            // --- Bundles (components resolved by first variant of each category) ---
            ['cat' => 'Soft Case', 'name' => 'Paket Proteksi: Soft Case + Tempered Glass', 'brand' => 'ADVAN', 'archetype' => 'bundle', 'buy' => 0, 'sell' => 35000, 'weight' => 50, 'components' => ['Soft Case', 'Screen Guard'], 'package' => '1x Soft Case + 1x Tempered Glass 9H', 'desc' => 'Paket hemat proteksi layar dan bodi.'],
            ['cat' => 'Hard Case', 'name' => 'Paket Travel: Hard Case + Phone Holder', 'brand' => 'ALDO', 'archetype' => 'bundle', 'buy' => 0, 'sell' => 75000, 'weight' => 180, 'components' => ['Hard Case', 'Phone Holder'], 'package' => '1x Hard Case + 1x Phone Holder Mobil', 'desc' => 'Paket berkendara aman.'],
        ];
    }
}
