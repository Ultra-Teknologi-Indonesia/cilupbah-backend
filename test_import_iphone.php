<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Product\Services\ProductImportService;
use Illuminate\Support\Facades\DB;

try {
    $service = app(ProductImportService::class);
    
    $row = [
        'item_category_id' => null,
        'category' => 'Elektronik -> Handphone',
        'item_group_name' => 'iPhone 17 Pro Max',
        'description' => 'Apple iPhone 17 Pro Max terbaru dengan chip A19 Pro',
        'package_weight' => 500,
        'package_length' => 20,
        'package_width' => 10,
        'package_height' => 5,
        'brand' => 'Apple',
        'item_code' => 'IPH17PM-256-BLK',
        'sell_price' => 24000000,
        'barcode' => '1234567890123',
        'image_url1' => 'https://via.placeholder.com/500?text=iPhone+17+Pro+Max'
    ];

    echo "Mencoba import produk: " . $row['item_group_name'] . "...\n";
    $service->processSingleProductRow($row);
    echo "Produk berhasil di-import (Upsert) ke database!\n\n";

    // Verifikasi di DB
    $product = DB::table('products')->where('name', 'iPhone 17 Pro Max')->first();
    echo "Data Product:\n";
    echo "- ID: {$product->id}\n";
    echo "- Nama: {$product->name}\n";
    
    $variant = DB::table('product_variants')->where('product_id', $product->id)->first();
    echo "\nData Varian:\n";
    echo "- SKU: {$variant->sku}\n";
    echo "- Harga Jual: Rp " . number_format($variant->sell_price, 0, ',', '.') . "\n";

} catch (\Exception $e) {
    echo "Terjadi Error: " . $e->getMessage() . "\n";
}
