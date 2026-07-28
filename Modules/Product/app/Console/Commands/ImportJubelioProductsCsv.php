<?php

namespace Modules\Product\Console\Commands;

use App\Services\UploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;

class ImportJubelioProductsCsv extends Command
{
    /**
     * Nama dan signature perintah console.
     *
     * @var string
     */
    protected $signature = 'product:import-jubelio-csv 
        {path? : Path file CSV Jubelio} 
        {--dry-run : Simulasi import tanpa menyimpan data ke database}
        {--limit=0 : Batasi jumlah baris CSV yang diproses (0 = tanpa batas)}
        {--mirror-images : Download gambar dari URL eksternal ke Object Storage internal S3/MinIO}';

    /**
     * Deskripsi perintah console.
     *
     * @var string
     */
    protected $description = 'Import data master produk & variant dari file CSV Jubelio (support dry-run & limit)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $workspacePath = base_path('../product-2026-07-28-07-22-42.271.csv');
        $csvPath = $this->argument('path') ?? $workspacePath;

        if (! file_exists($csvPath)) {
            $fallbackPath = '/Users/darrielmarkerizal/Coding/cilupbah-superapp/product-2026-07-28-07-22-42.271.csv';
            if (file_exists($fallbackPath)) {
                $csvPath = $fallbackPath;
            } else {
                $this->error("File CSV tidak ditemukan di lokasi: {$csvPath}");
                return Command::FAILURE;
            }
        }

        if ($isDryRun) {
            $this->warn("=================================================");
            $this->warn(" 🔍 MODE DRY-RUN (SIMULASI MAPPING) DIAKTIFKAN");
            $this->warn(" Tidak ada data yang akan disimpan ke database.");
            $this->warn("=================================================");
        }

        $this->info("Membaca file CSV Jubelio dari: {$csvPath}");

        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            $this->error("Gagal membuka file CSV.");
            return Command::FAILURE;
        }

        // Ambil header CSV
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error("File CSV kosong.");
            fclose($handle);
            return Command::FAILURE;
        }

        // Peta index kolom
        $columnIndex = [];
        foreach ($header as $idx => $colName) {
            $cleanColName = trim($colName, "\xEF\xBB\xBF "); // Strip UTF-8 BOM & spaces
            $columnIndex[$cleanColName] = $idx;
        }

        $categoryCache = [];
        $categorySummary = []; // name => ['status' => 'EXISTING'|'NEW', 'id' => ...]
        $productGroupCache = []; // groupKey => product_id
        $productSampleMapping = []; // groupKey => ['name' => ..., 'item_group_id' => ..., 'variants' => []]

        $importedProductsCount = 0;
        $importedVariantsCount = 0;

        DB::beginTransaction();

        try {
            $lineCount = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $lineCount++;
                if ($limit > 0 && $lineCount > $limit) {
                    $this->info("Batas limit {$limit} baris tercapai.");
                    break;
                }

                if (count($data) < 4) {
                    continue;
                }

                $itemId       = trim($data[$columnIndex['Item ID'] ?? 0] ?? '');
                $itemGroupId  = trim($data[$columnIndex['Item Group ID'] ?? 1] ?? '');
                $name         = trim($data[$columnIndex['Name'] ?? 2] ?? '');
                $sku          = trim($data[$columnIndex['SKU'] ?? 3] ?? '');
                $categoryName = trim($data[$columnIndex['Category Name'] ?? 4] ?? '');
                $variation    = trim($data[$columnIndex['Variation'] ?? 5] ?? '');
                $description  = trim($data[$columnIndex['Description'] ?? 6] ?? '');
                $weight       = (float) ($data[$columnIndex['Package Weight'] ?? 7] ?? 0);
                $sellPrice    = (float) ($data[$columnIndex['Sell Price'] ?? 11] ?? 0);

                if (empty($name) || empty($sku)) {
                    continue;
                }

                // 1. Resolve Category
                if (empty($categoryName)) {
                    $categoryName = 'Uncategorized';
                }

                if (! isset($categoryCache[$categoryName])) {
                    $existingCat = Category::where('name', $categoryName)->first();
                    if ($existingCat) {
                        $categoryCache[$categoryName] = $existingCat->id;
                        $categorySummary[$categoryName] = [
                            'name' => $categoryName,
                            'status' => 'EXISTING',
                            'cat_id' => $existingCat->id,
                        ];
                    } else {
                        $cat = Category::create([
                            'name' => $categoryName,
                            'is_active' => true,
                            'is_enabled' => true,
                            'source' => 'custom',
                        ]);
                        $categoryCache[$categoryName] = $cat->id;
                        $categorySummary[$categoryName] = [
                            'name' => $categoryName,
                            'status' => 'BUAT BARU',
                            'cat_id' => $cat->id,
                        ];
                    }
                }
                $categoryId = $categoryCache[$categoryName];

                // 2. Master Product Parent (Grouping by Item Group ID / Name)
                $groupKey = ! empty($itemGroupId) ? "group_{$itemGroupId}" : "name_" . Str::slug($name);

                if (! isset($productGroupCache[$groupKey])) {
                    $existingProduct = Product::where('name', $name)->first();
                    if ($existingProduct) {
                        $product = $existingProduct;
                    } else {
                        $product = Product::create([
                            'category_id' => $categoryId,
                            'name' => $name,
                            'sku' => ! empty($itemGroupId) ? "GRP-{$itemGroupId}" : $sku,
                            'description' => $description,
                            'search_keyword' => Str::lower(str_replace(' ', ',', $name)),
                            'weight' => $weight > 0 ? $weight : 100,
                            'weight_unit' => 'gram',
                            'condition' => 'NEW',
                            'is_active' => true,
                            'status' => Product::STATUS_MASTER,
                            'verified_at' => now(),
                            'is_bundle' => false,
                            'is_stored' => true,
                            'is_sold' => true,
                            'is_purchased' => true,
                        ]);
                        $importedProductsCount++;
                    }
                    $productGroupCache[$groupKey] = $product->id;
                    $productSampleMapping[$groupKey] = [
                        'name' => Str::limit($name, 45),
                        'item_group_id' => $itemGroupId ?: '-',
                        'category' => $categoryName ?: '-',
                        'variants' => [],
                    ];
                }
                $productId = $productGroupCache[$groupKey];

                // 3. Variant (ProductVariant)
                $existingVariant = ProductVariant::where('sku', $sku)->first();
                if (! $existingVariant) {
                    $variant = ProductVariant::create([
                        'product_id' => $productId,
                        'sku' => $sku,
                        'barcode' => $sku,
                        'buy_price' => (float) round($sellPrice * 0.7),
                        'sell_price' => $sellPrice,
                        'weight' => $weight > 0 ? $weight : 100,
                        'is_active' => true,
                        'min_stock' => 5,
                        'safe_stock' => 10,
                    ]);
                    $importedVariantsCount++;
                } else {
                    $variant = $existingVariant;
                }

                // 3.1 Populate variant options from Variation string
                if (! empty($variation)) {
                    $parts = explode(',', $variation);
                    foreach ($parts as $idx => $part) {
                        $val = trim($part);
                        if ($val === '') continue;
                        $attrName = count($parts) > 1 ? 'Varian ' . ($idx + 1) : 'Varian';
                        $attribute = Attribute::firstOrCreate(
                            ['name' => $attrName],
                            ['type' => 'sales']
                        );

                        DB::table('variant_options')->updateOrInsert(
                            [
                                'variant_id'   => $variant->id,
                                'attribute_id' => $attribute->id,
                            ],
                            [
                                'value'      => $val,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }

                // Track sample variant for output display
                if (isset($productSampleMapping[$groupKey]) && count($productSampleMapping[$groupKey]['variants']) < 4) {
                    $productSampleMapping[$groupKey]['variants'][] = [
                        'sku' => $sku,
                        'variation' => $variation ?: '-',
                        'price' => 'Rp ' . number_format($sellPrice, 0, ',', '.'),
                    ];
                }

                // 4. Media (Image 1 s/d 5)
                $shouldMirror = (bool) $this->option('mirror-images');
                $uploadService = ($shouldMirror && ! $isDryRun) ? app(UploadService::class) : null;

                for ($imgIdx = 1; $imgIdx <= 5; $imgIdx++) {
                    $imgKey = "Image {$imgIdx}";
                    if (isset($columnIndex[$imgKey]) && ! empty($data[$columnIndex[$imgKey]])) {
                        $imageUrl = trim($data[$columnIndex[$imgKey]]);
                        if (! empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                            $mediaUuid = null;
                            $finalUrl = $imageUrl;

                            if ($shouldMirror && $uploadService) {
                                $mediaObj = $uploadService->storeFromUrl($imageUrl);
                                if ($mediaObj) {
                                    $finalUrl = $mediaObj->getUrl();
                                    $mediaUuid = $mediaObj->uuid;
                                }
                            }

                            ProductMedia::firstOrCreate(
                                [
                                    'product_id' => $productId,
                                    'variant_id' => $variant->id,
                                    'url'        => $finalUrl,
                                ],
                                [
                                    'media_uuid' => $mediaUuid,
                                    'media_type' => 'image',
                                    'sort_order' => $imgIdx - 1,
                                    'is_primary' => ($imgIdx === 1),
                                ]
                            );
                        }
                    }
                }

                if (! $isDryRun && $lineCount % 500 === 0) {
                    DB::commit();
                    DB::beginTransaction();
                    $this->info("Memproses baris ke-{$lineCount}...");
                }
            }

            if ($isDryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            fclose($handle);

            // Display Dry-Run Report Tables
            $this->info("\n=================================================");
            $this->info(" 📊 HASIL ANALISIS MAPPING DATA (JUBELIO → INTERNAL)");
            $this->info("=================================================");

            // Table 1: Category Mapping
            $this->info("\n1. Hasil Mapping Kategori:");
            $catRows = [];
            foreach ($categorySummary as $cat) {
                $catRows[] = [$cat['name'], $cat['status'], $cat['cat_id'] ?? '-'];
            }
            $this->table(['Kategori di CSV', 'Status Kategori', 'Category ID'], array_slice($catRows, 0, 15));

            // Table 2: Product & Variant Samples
            $this->info("\n2. Sample Structure Master Produk & Varian (5 Sample Pertama):");
            foreach (array_slice($productSampleMapping, 0, 5) as $grpKey => $prod) {
                $this->line("<comment>► Master Product:</comment> <info>{$prod['name']}</info> (Group ID: {$prod['item_group_id']} | Kat: {$prod['category']})");
                foreach ($prod['variants'] as $v) {
                    $this->line("   ├── SKU Variant : <info>{$v['sku']}</info> | Variation: {$v['variation']} | Harga: {$v['price']}");
                }
            }

            $this->info("\n------------------------------------------------");
            $this->info("🎉 Ringkasan Statistik:");
            $this->info("   • Total Baris Dibarikan : {$lineCount}");
            $this->info("   • Total Kategori        : " . count($categorySummary));
            $this->info("   • Total Master Produk   : {$importedProductsCount}");
            $this->info("   • Total Variant SKU     : {$importedVariantsCount}");
            $this->info("------------------------------------------------");

            if ($isDryRun) {
                $this->warn("ℹ️  MODE DRY-RUN SELESAI: Transaction di-rollback. Tidak ada perubahan yang disimpan ke database.");
            } else {
                $this->info("✅ Sukses meng-import data ke database!");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            $this->error("Terjadi kesalahan saat memproses CSV: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
