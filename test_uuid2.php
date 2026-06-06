<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$product = Modules\Product\Models\Product::first();
if ($product) {
    echo "ID: " . $product->id . " (Length: " . strlen($product->id) . ")\n";
} else {
    echo "No product found.\n";
}
