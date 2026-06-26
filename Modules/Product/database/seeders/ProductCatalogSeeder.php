<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Phone-model variation axis ("Tipe HP") applied to EVERY non-bundle product.
     * Full iPhone 11–17 line-up (base / mini / Plus / Pro / Pro Max per generation)
     * plus popular Android models. No product is single-variant or carries a model
     * name in its title — the model is always a variant.
     */
    private const MODELS = [
        'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max',
        'iPhone 12', 'iPhone 12 mini', 'iPhone 12 Pro', 'iPhone 12 Pro Max',
        'iPhone 13', 'iPhone 13 mini', 'iPhone 13 Pro', 'iPhone 13 Pro Max',
        'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
        'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max',
        'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max',
        'iPhone 17', 'iPhone 17 Plus', 'iPhone 17 Pro', 'iPhone 17 Pro Max',
        'Galaxy S24', 'Galaxy S25', 'Galaxy A55', 'Redmi Note 13', 'POCO X6',
    ];

    /**
     * Curated, real product photos per leaf category (hand-picked & verified on
     * Unsplash / Pexels CDNs). The seeder downloads these at runtime and uploads
     * them to S3. If a URL ever fails it falls back to a keyword photo from
     * loremflickr, then to a generated placeholder — so seeding never breaks.
     */
    private const CATEGORY_IMAGES = [
        'Adaptor + Kabel' => [
            'https://images.unsplash.com/photo-1583394838336-acd977736f90',
            'https://images.unsplash.com/photo-1606131731446-5568d87113aa',
            'https://images.unsplash.com/photo-1601524909162-ae8725290836',
            'https://images.unsplash.com/photo-1558618666-fcd25c85cd64',
            'https://images.pexels.com/photos/4526407/pexels-photo-4526407.jpeg',
        ],
        'Anti Crack Case' => [
            'https://images.unsplash.com/photo-1601593346740-925612772716',
            'https://images.unsplash.com/photo-1556656793-08538906a9f8',
            'https://images.unsplash.com/photo-1592890288564-76628a30a657',
            'https://images.pexels.com/photos/699122/pexels-photo-699122.jpeg',
        ],
        'Hard Case' => [
            'https://images.unsplash.com/photo-1541877944-ac82a091518a',
            'https://images.unsplash.com/photo-1556656793-08538906a9f8',
            'https://images.unsplash.com/photo-1601593346740-925612772716',
        ],
        'Leather Case' => [
            'https://images.unsplash.com/photo-1592890288564-76628a30a657',
            'https://images.unsplash.com/photo-1541877944-ac82a091518a',
            'https://images.pexels.com/photos/699122/pexels-photo-699122.jpeg',
        ],
        'Skin Handphone' => [
            'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5',
            'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9',
            'https://images.unsplash.com/photo-1556656793-08538906a9f8',
        ],
        'Soft Case' => [
            'https://images.unsplash.com/photo-1556656793-08538906a9f8',
            'https://images.unsplash.com/photo-1601593346740-925612772716',
            'https://images.unsplash.com/photo-1592890288564-76628a30a657',
            'https://images.pexels.com/photos/699122/pexels-photo-699122.jpeg',
        ],
        'Gantungan HP' => [
            'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9',
            'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5',
        ],
        'Screen Guard' => [
            'https://images.unsplash.com/photo-1517336714731-489689fd1ca8',
            'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9',
            'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5',
        ],
        'Strap' => [
            'https://images.unsplash.com/photo-1546868871-7041f2a55e12',
            'https://images.unsplash.com/photo-1434493789847-2f02dc6ca35d',
            'https://images.pexels.com/photos/393047/pexels-photo-393047.jpeg',
        ],
        'Lazypod' => [
            'https://images.unsplash.com/photo-1625842268584-8f3296236761',
            'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9',
        ],
        'Phone Holder' => [
            'https://images.unsplash.com/photo-1625842268584-8f3296236761',
            'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9',
            'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5',
        ],
    ];

    private array $categoryIds = [];      // name => id
    private array $imageBytesCache = [];   // url|keyword => raw bytes
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
        // Bundles are a single sellable package; every other product gets the
        // full "Tipe HP" model matrix (so nothing is single-variant or model-named).
        $variantIds = [];
        if ($isBundle) {
            $variant = $this->makeVariant($product, $sku, $e, null);
            $variantIds[] = $variant->id;
            $this->attachBundleItems($product, $catName, $e);
        } else {
            $variantIds = $this->makeVariantMatrix($product, $sku, $e);
        }

        if (! isset($this->firstVariantByCat[$catName])) {
            $this->firstVariantByCat[$catName] = $variantIds[0];
        }

        // ---- Media (S3) ----
        // Product-level photos only: model variants share the same product image,
        // so we never download one image per variant.
        $this->attachMedia($product, $catName, $sku);

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

    /** Build one variant per phone model ("Tipe HP" axis) for a product. */
    private function makeVariantMatrix(Product $product, string $baseSku, array $e): array
    {
        $axes = [['attr' => 'Tipe HP', 'options' => self::MODELS]];
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

    private function attachMedia(Product $product, string $catName, string $sku): void
    {
        if ($product->media()->exists()) {
            return;
        }

        // Deterministic starting offset per product so different products in the
        // same category don't all open with the identical photo.
        $base = abs(crc32($sku));

        // Product-level: primary + 2 gallery shots.
        $primary = $this->imageFor($catName, $base, $product->name);
        $this->storeMedia($product->id, null, "products/seed/{$sku}/main.jpg", $primary, 0, true);

        $gallery = $this->imageFor($catName, $base + 1, $product->name);
        $this->storeMedia($product->id, null, "products/seed/{$sku}/gallery.jpg", $gallery, 1, false);

        $gallery2 = $this->imageFor($catName, $base + 2, $product->name);
        $this->storeMedia($product->id, null, "products/seed/{$sku}/gallery2.jpg", $gallery2, 2, false);
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

    /**
     * Resolve a real product photo for a category. Picks the curated URL at
     * $index (cycled), then falls back to a keyword photo, then a placeholder.
     */
    private function imageFor(string $catName, int $index, string $label): string
    {
        $pool = self::CATEGORY_IMAGES[$catName] ?? [];
        if ($pool) {
            $base = $pool[$index % count($pool)];
            $url = $base . (str_contains($base, '?') ? '&' : '?') . 'w=640&h=640&fit=crop&q=80&fm=jpg';
            $bytes = $this->download($url);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        // Fallback 1: keyword photo from loremflickr (still a real internet image).
        $keyword = self::CATEGORIES[$catName][1] ?? $label;
        $lock = abs(crc32($catName . $index)) % 90 + 1;
        $bytes = $this->download(
            'https://loremflickr.com/640/640/' . rawurlencode(str_replace(' ', ',', $keyword)) . '?lock=' . $lock
        );
        if ($bytes !== null) {
            return $bytes;
        }

        // Fallback 2: generated placeholder.
        return $this->placeholder($label);
    }

    /** Download an image, cached by URL. Returns null on failure / non-image. */
    private function download(string $url): ?string
    {
        if (array_key_exists($url, $this->imageBytesCache)) {
            return $this->imageBytesCache[$url];
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 12, 'follow_location' => 1, 'user_agent' => 'cilupbah-seeder/1.0'],
            'https' => ['timeout' => 12, 'follow_location' => 1, 'user_agent' => 'cilupbah-seeder/1.0'],
        ]);
        $bytes = @file_get_contents($url, false, $ctx);

        if (! $bytes || strlen($bytes) < 1500) {
            return $this->imageBytesCache[$url] = null;
        }

        return $this->imageBytesCache[$url] = $bytes;
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
     * The full catalog (35 products). archetype drives type-specific behaviour
     * (serial / consignment / preorder / bundle); every non-bundle product carries
     * the full "Tipe HP" model matrix, so no product is single-variant or names a
     * specific phone model. Every product name is generic and >= 25 characters.
     */
    private function catalog(): array
    {
        return [
            // --- Adaptor + Kabel ---
            ['cat' => 'Adaptor + Kabel', 'name' => 'Adaptor Fast Charging GaN 65W Original Bergaransi', 'brand' => 'ANKER', 'archetype' => 'serial', 'buy' => 145000, 'margin' => 0.5, 'cod' => true, 'weight' => 120, 'desc' => 'Charger GaN 65W bergaransi resmi, dilacak per serial number.'],
            ['cat' => 'Adaptor + Kabel', 'name' => 'Kabel Data USB-C to USB-C 100W Premium 1.5 Meter', 'brand' => 'ACOME', 'archetype' => 'regular', 'buy' => 38000, 'margin' => 0.6, 'cod' => true, 'weight' => 60],
            ['cat' => 'Adaptor + Kabel', 'name' => 'Kabel Charger Lightning Braided Nylon Anti Putus', 'brand' => 'ACOME', 'archetype' => 'regular', 'buy' => 29000, 'margin' => 0.65, 'weight' => 45],

            // --- Anti Crack Case ---
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Bening Premium Transparan Glossy', 'brand' => 'Apple', 'archetype' => 'regular', 'buy' => 18000, 'margin' => 0.8, 'cod' => true, 'weight' => 40],
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Hybrid Pelindung Sudut Bumper', 'brand' => 'ADVAN', 'archetype' => 'regular', 'buy' => 17000, 'margin' => 0.8, 'weight' => 42],
            ['cat' => 'Anti Crack Case', 'name' => 'Anti Crack Case Acrylic Anti Kuning Tahan Lama', 'brand' => 'ADVAN', 'archetype' => 'regular', 'buy' => 22000, 'margin' => 0.85],

            // --- Hard Case ---
            ['cat' => 'Hard Case', 'name' => 'Hard Case Matte Custom Print Sablon Satuan', 'brand' => 'ALDO', 'archetype' => 'preorder', 'buy' => 25000, 'margin' => 1.0, 'weight' => 55, 'desc' => 'Hard case cetak custom sesuai pesanan, indikasi 7 hari kerja.'],
            ['cat' => 'Hard Case', 'name' => 'Hard Case Slim Frosted Doff Anti Slip Premium', 'brand' => 'ALDO', 'archetype' => 'regular', 'buy' => 19000, 'margin' => 0.9, 'cod' => true],
            ['cat' => 'Hard Case', 'name' => 'Hard Case Armor Shockproof Anti Banting Militer', 'brand' => 'ARASHI', 'archetype' => 'regular', 'buy' => 31000, 'margin' => 0.85, 'cod' => true, 'weight' => 70],

            // --- Leather Case ---
            ['cat' => 'Leather Case', 'name' => 'Leather Case Kulit Sapi Asli Premium Handmade', 'brand' => 'ALDO', 'archetype' => 'consignment', 'buy' => 95000, 'margin' => 0.7, 'weight' => 75, 'desc' => 'Kulit sapi asli, titipan mitra (konsinyasi).'],
            ['cat' => 'Leather Case', 'name' => 'Leather Flip Wallet Case Dompet Slot Kartu', 'brand' => 'ALDO', 'archetype' => 'regular', 'buy' => 48000, 'margin' => 0.75],
            ['cat' => 'Leather Case', 'name' => 'Leather Case Magnetic Slim MagSafe Compatible', 'brand' => 'ARASHI', 'archetype' => 'regular', 'buy' => 52000, 'margin' => 0.7, 'weight' => 60],

            // --- Skin Handphone ---
            ['cat' => 'Skin Handphone', 'name' => 'Skin Garskin Carbon 3M Tekstur Doff Custom', 'brand' => '3M', 'archetype' => 'preorder', 'buy' => 15000, 'margin' => 1.2, 'weight' => 20],
            ['cat' => 'Skin Handphone', 'name' => 'Skin Garskin Motif Marble Premium Glossy Cut', 'brand' => '3M', 'archetype' => 'regular', 'buy' => 12000, 'margin' => 1.3],
            ['cat' => 'Skin Handphone', 'name' => 'Skin Anti Gores Belakang Matte Transparan Doff', 'brand' => '3M', 'archetype' => 'regular', 'buy' => 10000, 'margin' => 1.4, 'weight' => 15],

            // --- Soft Case ---
            ['cat' => 'Soft Case', 'name' => 'Soft Case Silikon Premium Lembut Elastis Anti Debu', 'brand' => 'ADVAN', 'archetype' => 'regular', 'buy' => 14000, 'margin' => 1.0, 'cod' => true],
            ['cat' => 'Soft Case', 'name' => 'Soft Case Jelly Bening Ultra Thin Clear Slim', 'brand' => 'ADVAN', 'archetype' => 'regular', 'buy' => 9000, 'margin' => 1.1, 'cod' => true, 'weight' => 30],
            ['cat' => 'Soft Case', 'name' => 'Soft Case Macaron Pastel Warna Lembut Doff', 'brand' => 'ADVAN', 'archetype' => 'regular', 'buy' => 11000, 'margin' => 1.1],

            // --- Gantungan HP ---
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Tali Manik Beads Handmade Lucu', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 13000, 'margin' => 1.2],
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Lanyard Strap Polos Panjang Nyaman', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 8000, 'margin' => 1.3, 'cod' => true, 'weight' => 25],
            ['cat' => 'Gantungan HP', 'name' => 'Gantungan HP Crossbody Strap Selempang Adjustable', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 24000, 'margin' => 0.9, 'weight' => 50],

            // --- Screen Guard ---
            ['cat' => 'Screen Guard', 'name' => 'Tempered Glass Anti Gores Bening 9H Full Lem', 'brand' => 'ANKER', 'archetype' => 'regular', 'buy' => 7000, 'margin' => 1.5, 'cod' => true],
            ['cat' => 'Screen Guard', 'name' => 'Tempered Glass Anti Spy Privacy Gelap Pelindung', 'brand' => 'ANKER', 'archetype' => 'regular', 'buy' => 12000, 'margin' => 1.4, 'weight' => 18],
            ['cat' => 'Screen Guard', 'name' => 'Hydrogel Film Pelindung Layar Full Cover Lentur', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 6000, 'margin' => 1.6, 'weight' => 12],

            // --- Strap (Smartwatch) ---
            ['cat' => 'Strap', 'name' => 'Strap Tali Jam Silikon Sport Premium Lembut', 'brand' => 'Apple', 'archetype' => 'regular', 'buy' => 22000, 'margin' => 1.0, 'cod' => true],
            ['cat' => 'Strap', 'name' => 'Strap Tali Jam Kulit Asli Premium Elegan Klasik', 'brand' => 'ALDO', 'archetype' => 'consignment', 'buy' => 65000, 'margin' => 0.7, 'weight' => 35],
            ['cat' => 'Strap', 'name' => 'Strap Tali Jam Nylon Sport Loop Adjustable Adem', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 18000, 'margin' => 1.1],

            // --- Lazypod ---
            ['cat' => 'Lazypod', 'name' => 'Lazypod Flexible Premium Aluminium Kokoh Tahan', 'brand' => 'AILITE', 'archetype' => 'serial', 'buy' => 55000, 'margin' => 0.8, 'weight' => 220, 'desc' => 'Lazypod aluminium premium bergaransi, dilacak per serial.'],
            ['cat' => 'Lazypod', 'name' => 'Lazypod Gurita Mini Tripod Fleksibel Universal', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 28000, 'margin' => 0.9, 'cod' => true, 'weight' => 150],
            ['cat' => 'Lazypod', 'name' => 'Lazypod Clamp Meja Panjang Adjustable Kuat', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 42000, 'margin' => 0.85, 'weight' => 300],

            // --- Phone Holder ---
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Mobil Magnetic Dashboard Kuat', 'brand' => 'ANKER', 'archetype' => 'regular', 'buy' => 35000, 'margin' => 0.9, 'cod' => true, 'weight' => 110],
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Motor Anti Getar Cengkraman Spion', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 48000, 'margin' => 0.85],
            ['cat' => 'Phone Holder', 'name' => 'Phone Holder Meja Adjustable Lipat Aluminium', 'brand' => 'AILITE', 'archetype' => 'regular', 'buy' => 26000, 'margin' => 0.95, 'weight' => 130],

            // --- Bundles (single package; components resolved by first variant of each category) ---
            ['cat' => 'Soft Case', 'name' => 'Paket Proteksi Lengkap Soft Case dan Tempered Glass', 'brand' => 'ADVAN', 'archetype' => 'bundle', 'buy' => 0, 'sell' => 35000, 'weight' => 50, 'components' => ['Soft Case', 'Screen Guard'], 'package' => '1x Soft Case + 1x Tempered Glass 9H', 'desc' => 'Paket hemat proteksi layar dan bodi.'],
            ['cat' => 'Hard Case', 'name' => 'Paket Travel Hard Case dan Phone Holder Mobil Mudik', 'brand' => 'ALDO', 'archetype' => 'bundle', 'buy' => 0, 'sell' => 75000, 'weight' => 180, 'components' => ['Hard Case', 'Phone Holder'], 'package' => '1x Hard Case + 1x Phone Holder Mobil', 'desc' => 'Paket berkendara aman.'],
        ];
    }
}
