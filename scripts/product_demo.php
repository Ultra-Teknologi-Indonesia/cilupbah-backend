<?php

/**
 * Script demo: Create 10 internal products, push to TikTok, pull from TikTok
 */

define('API_BASE', 'http://localhost:8000');
define('TOKEN', '019ea5bc-60b7-7233-9edf-a863ad523398|WVzwFUsWpmDGYTCAJH77z5cQcDs0cgfZSywUQ5Jb50d5d162');
define('TIKTOK_SHOP_ID', '7494685794425930858');

// ─── helper ──────────────────────────────────────────────────────────────────

function api(string $method, string $path, array $body = []): array
{
    $ch = curl_init(API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . TOKEN,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $method !== 'GET' ? json_encode($body) : null,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['__curl_error' => $err, '__http_code' => $httpCode];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['__raw' => $raw, '__http_code' => $httpCode];
    }

    $decoded['__http_code'] = $httpCode;
    return $decoded;
}

function ok(int $code): bool  { return $code >= 200 && $code < 300; }
function log_step(string $msg): void { echo "\n" . str_repeat('─', 60) . "\n$msg\n"; }
function log_ok(string $msg): void  { echo "  ✓ $msg\n"; }
function log_err(string $msg): void { echo "  ✗ $msg\n"; }
function log_info(string $msg): void { echo "    $msg\n"; }

// ─── product definitions ──────────────────────────────────────────────────────

$products = [
    // 1. Single variant – no variant options (plain SKU)
    [
        'name'        => 'Wardah Lightening Serum Vitamin C',
        'description' => 'Serum wajah dengan kandungan Vitamin C 10% yang mencerahkan dan meratakan warna kulit secara alami. Cocok untuk semua jenis kulit, bebas paraben.',
        'category_id' => 3,   // Serum
        'brand_id'    => null,
        'weight'      => 0.08,
        'length'      => 4.0, 'width' => 4.0, 'height' => 12.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1556228578-8c89e6adf883?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
            ['url' => 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => false, 'sort_order' => 2],
        ],
        'variants' => [
            ['sku' => 'WRD-SERUM-VC-001', 'sell_price' => 85000, 'is_active' => true],
        ],
    ],

    // 2. Single variant – with wholesale prices
    [
        'name'        => 'Emina Sun Battle SPF35 PA+++ Sunscreen',
        'description' => 'Sunscreen ringan berbasis air dengan SPF35 PA+++ untuk perlindungan optimal dari sinar UVA dan UVB. Tekstur cair, tidak berminyak, cocok untuk pemakaian sehari-hari.',
        'category_id' => 5,   // Sunscreen
        'brand_id'    => null,
        'weight'      => 0.09,
        'length'      => 5.0, 'width' => 5.0, 'height' => 14.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1571781926291-c477ebfd024b?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
        ],
        'variants' => [
            [
                'sku'        => 'EMN-SUN-SPF35-001',
                'sell_price' => 48000,
                'is_active'  => true,
                'wholesale_prices' => [
                    ['min_qty' => 5,  'price' => 44000, 'customer_type' => 'GENERAL'],
                    ['min_qty' => 12, 'price' => 40000, 'customer_type' => 'GENERAL'],
                ],
            ],
        ],
    ],

    // 3. Multi variant – 3 shades (Warna)
    [
        'name'        => 'Maybelline Fit Me Matte + Poreless Foundation',
        'description' => 'Foundation matte dengan formula micro-powder yang menyerap minyak berlebih dan mengecilkan tampilan pori. Memberikan tampilan kulit halus, segar, dan tahan lama hingga 12 jam.',
        'category_id' => 16,  // Foundation
        'brand_id'    => null,
        'weight'      => 0.12,
        'length'      => 4.0, 'width' => 4.0, 'height' => 15.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
            ['url' => 'https://images.unsplash.com/photo-1487412947147-5cebf100ffc2?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => false, 'sort_order' => 2],
        ],
        'variation_types' => [
            ['attribute_id' => 49, 'sort_order' => 1], // Warna
        ],
        'variants' => [
            ['sku' => 'MAYB-FMF-110', 'sell_price' => 129000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => 'Ivory 110']]],
            ['sku' => 'MAYB-FMF-220', 'sell_price' => 129000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => 'Natural Beige 220']]],
            ['sku' => 'MAYB-FMF-310', 'sell_price' => 129000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => 'Warm Nude 310']]],
        ],
    ],

    // 4. Single variant – basic body lotion
    [
        'name'        => 'Scarlett Whitening Bright Glow Body Lotion',
        'description' => 'Lotion tubuh pemutih dengan kandungan Glutathione dan Vitamin E yang mencerahkan serta melembutkan kulit. Aroma parfum tahan lama sepanjang hari.',
        'category_id' => 11,  // Lotion
        'brand_id'    => null,
        'weight'      => 0.25,
        'length'      => 6.0, 'width' => 6.0, 'height' => 18.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1567016526105-22da7c13161a?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
        ],
        'variants' => [
            ['sku' => 'SCL-LOTION-BRT-300', 'sell_price' => 95000, 'is_active' => true],
        ],
    ],

    // 5. Multi variant – 3 types (Varian)
    [
        'name'        => 'MS Glow Sheet Mask Perawatan Intensif',
        'description' => 'Sheet mask premium berbahan essence serum tinggi untuk perawatan wajah intensif. Formula unik yang menyesuaikan kebutuhan kulit: cerahkan, atasi jerawat, atau hidrasi mendalam.',
        'category_id' => 7,   // Masker Wajah
        'brand_id'    => null,
        'weight'      => 0.03,
        'length'      => 20.0, 'width' => 14.0, 'height' => 1.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
        ],
        'variation_types' => [
            ['attribute_id' => 47, 'sort_order' => 1], // Varian
        ],
        'variants' => [
            ['sku' => 'MSG-MASK-BRIGHT', 'sell_price' => 25000, 'is_active' => true, 'options' => [['attribute_id' => 47, 'value' => 'Brightening']]],
            ['sku' => 'MSG-MASK-ANTIAC', 'sell_price' => 25000, 'is_active' => true, 'options' => [['attribute_id' => 47, 'value' => 'Anti-Acne']]],
            ['sku' => 'MSG-MASK-MOISTUR', 'sell_price' => 25000, 'is_active' => true, 'options' => [['attribute_id' => 47, 'value' => 'Moisturizing']]],
        ],
    ],

    // 6. Single variant – toner
    [
        'name'        => 'Somethinc Hyaluronic Acid Toner 2% + B5',
        'description' => 'Toner dengan konsentrasi Hyaluronic Acid 2% yang dikombinasikan Vitamin B5 untuk hidrasi kulit mendalam. Formula ringan yang cepat meresap dan membuat kulit terasa kenyal seharian.',
        'category_id' => 6,   // Toner
        'brand_id'    => null,
        'weight'      => 0.18,
        'length'      => 5.0, 'width' => 5.0, 'height' => 16.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
            ['url' => 'https://images.unsplash.com/photo-1556228578-8c89e6adf883?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => false, 'sort_order' => 2],
        ],
        'variants' => [
            [
                'sku'        => 'SMC-TONER-HA-100',
                'sell_price' => 119000,
                'is_active'  => true,
                'wholesale_prices' => [
                    ['min_qty' => 3, 'price' => 109000, 'customer_type' => 'GENERAL'],
                    ['min_qty' => 6, 'price' => 99000,  'customer_type' => 'GENERAL'],
                ],
            ],
        ],
    ],

    // 7. Multi variant – 4 lip colors
    [
        'name'        => 'Implora Matte Lipstick Long-Lasting',
        'description' => 'Lipstik matte lokal dengan pigmen tinggi dan daya tahan 8 jam. Formula lembab tidak membuat bibir kering, mudah diaplikasikan dengan sekali usap.',
        'category_id' => 15,  // Lipstik
        'brand_id'    => null,
        'weight'      => 0.02,
        'length'      => 2.0, 'width' => 2.0, 'height' => 8.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1586495777744-4e6232bf2fd7?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
        ],
        'variation_types' => [
            ['attribute_id' => 49, 'sort_order' => 1], // Warna
        ],
        'variants' => [
            ['sku' => 'IMP-MATTE-01RV', 'sell_price' => 29000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '01 Red Velvet']]],
            ['sku' => 'IMP-MATTE-02RP', 'sell_price' => 29000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '02 Rose Pink']]],
            ['sku' => 'IMP-MATTE-03BN', 'sell_price' => 29000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '03 Berry Nude']]],
            ['sku' => 'IMP-MATTE-04CR', 'sell_price' => 29000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '04 Coral']]],
        ],
    ],

    // 8. Single variant – basic soap (no wholesale, no options)
    [
        'name'        => 'NASA Natural Milk Whitening Soap',
        'description' => 'Sabun mandi pemutih alami dengan kandungan susu dan ekstrak tanaman herbal pilihan. Kulit terasa bersih, lembut, dan cerah secara bertahap. Dermatologically tested.',
        'category_id' => 10,  // Sabun Mandi
        'brand_id'    => null,
        'weight'      => 0.15,
        'length'      => 8.0, 'width' => 6.0, 'height' => 3.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1574798834926-b39501d8eda2?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
        ],
        'variants' => [
            ['sku' => 'NASA-SOAP-MILK-80G', 'sell_price' => 35000, 'is_active' => true],
        ],
    ],

    // 9. Multi variant – 3 concealer shades (Ukuran + Warna)
    [
        'name'        => 'Emina Bare With Me Concealer',
        'description' => 'Concealer cair dengan coverage medium-to-full yang dapat di-blend sempurna untuk menutup kantung mata, noda gelap, dan bekas jerawat. Tahan air dan long lasting.',
        'category_id' => 17,  // Bedak
        'brand_id'    => null,
        'weight'      => 0.05,
        'length'      => 3.0, 'width' => 3.0, 'height' => 10.0,
        'is_active'   => true,
        'media'       => [
            ['url' => 'https://images.unsplash.com/photo-1487412947147-5cebf100ffc2?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
            ['url' => 'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => false, 'sort_order' => 2],
        ],
        'variation_types' => [
            ['attribute_id' => 49, 'sort_order' => 1], // Warna
        ],
        'variants' => [
            ['sku' => 'EMN-CONC-01PORC', 'sell_price' => 55000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '01 Porcelain']]],
            ['sku' => 'EMN-CONC-02IVORY', 'sell_price' => 55000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '02 Ivory']]],
            ['sku' => 'EMN-CONC-03NAT', 'sell_price' => 55000, 'is_active' => true, 'options' => [['attribute_id' => 49, 'value' => '03 Natural']]],
        ],
    ],

    // 10. Consignment – body scrub
    [
        'name'           => 'Mustika Ratu Lulur Tubuh Kunyit Putih',
        'description'    => 'Lulur tradisional Indonesia dengan bahan alami kunyit putih yang mencerahkan dan menghaluskan kulit. Mengangkat sel kulit mati secara lembut, meninggalkan kulit halus bercahaya. Produk titipan/konsinyasi.',
        'category_id'    => 12,  // Scrub
        'brand_id'       => null,
        'weight'         => 0.2,
        'length'         => 10.0, 'width' => 8.0, 'height' => 5.0,
        'is_active'      => true,
        'is_consignment' => true,
        'media'          => [
            ['url' => 'https://images.unsplash.com/photo-1515377905703-c4788e51af15?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => true, 'sort_order' => 1],
            ['url' => 'https://images.unsplash.com/photo-1567016526105-22da7c13161a?w=800&h=800&fit=crop&q=80', 'media_type' => 'image', 'is_primary' => false, 'sort_order' => 2],
        ],
        'variants' => [
            ['sku' => 'MR-LULUR-KP-200G', 'sell_price' => 65000, 'is_active' => true],
        ],
    ],
];

// ─── STEP 1: Create products ──────────────────────────────────────────────────

log_step("STEP 1: Creating 10 internal products");

$createdIds = [];
$failedProducts = [];

foreach ($products as $i => $product) {
    $no   = $i + 1;
    $name = $product['name'];

    $res  = api('POST', '/api/v1/products', $product);
    $code = $res['__http_code'];

    if (ok($code) && isset($res['data']['product_id'])) {
        $id = $res['data']['product_id'];
        $createdIds[] = $id;
        $consign = !empty($product['is_consignment']) ? ' [KONSINYASI]' : '';
        $varCount = count($product['variants']);
        $varLabel = $varCount === 1
            ? (isset($product['variation_types']) ? 'single variant' : 'no options')
            : "multi variant ({$varCount} SKU)";
        log_ok("#{$no} {$name}{$consign} → ID: {$id} [{$varLabel}]");
    } else {
        $failedProducts[] = ['index' => $i, 'name' => $name, 'response' => $res];
        log_err("#{$no} {$name} → GAGAL (HTTP {$code})");
        log_info(json_encode($res, JSON_PRETTY_PRINT));
    }
}

if (!empty($failedProducts)) {
    log_err("Ada " . count($failedProducts) . " produk yang gagal dibuat. Periksa log di atas.");
}

echo "\nTotal created: " . count($createdIds) . "/" . count($products) . "\n";

if (empty($createdIds)) {
    die("\nTidak ada produk yang berhasil dibuat. Proses dihentikan.\n");
}

// ─── STEP 2: Push ke TikTok ──────────────────────────────────────────────────

log_step("STEP 2: Pushing " . count($createdIds) . " products to TikTok (shop: " . TIKTOK_SHOP_ID . ")");

$pushedIds     = [];
$failedPushIds = [];

foreach ($createdIds as $productId) {
    $res  = api('POST', '/api/v1/tiktok/sync/products/push', [
        'shop_id'    => TIKTOK_SHOP_ID,
        'product_id' => $productId,
    ]);
    $code = $res['__http_code'];

    if (ok($code)) {
        $pushedIds[] = $productId;
        log_ok("Push OK → {$productId}");
        if (isset($res['message'])) {
            log_info($res['message']);
        }
    } else {
        $failedPushIds[] = $productId;
        $msg = $res['message'] ?? ($res['error'] ?? json_encode($res));
        log_err("Push GAGAL → {$productId}: {$msg}");
    }
}

echo "\nTotal pushed: " . count($pushedIds) . "/" . count($createdIds) . "\n";

// ─── STEP 3: Pull dari TikTok ─────────────────────────────────────────────────

log_step("STEP 3: Pulling products from TikTok (semua produk di luar yang baru dibuat)");

$res  = api('POST', '/api/v1/tiktok/auto-sync/pull-products');
$code = $res['__http_code'];

if (ok($code)) {
    log_ok("Pull berhasil (HTTP {$code})");
    if (isset($res['data'])) {
        log_info(json_encode($res['data'], JSON_PRETTY_PRINT));
    }
    if (isset($res['message'])) {
        log_info("Pesan: " . $res['message']);
    }
} else {
    log_err("Pull GAGAL (HTTP {$code})");
    log_info(json_encode($res, JSON_PRETTY_PRINT));
}

// ─── SUMMARY ─────────────────────────────────────────────────────────────────

log_step("SUMMARY");
echo "  Produk dibuat   : " . count($createdIds)  . "/" . count($products)  . "\n";
echo "  Produk di-push  : " . count($pushedIds)    . "/" . count($createdIds) . "\n";
echo "  Pull TikTok     : " . (ok($code) ? "Sukses" : "Gagal") . "\n";

if (!empty($failedPushIds)) {
    echo "\n  IDs yang gagal push:\n";
    foreach ($failedPushIds as $id) {
        echo "    - $id\n";
    }
}

echo "\n  Product IDs yang berhasil dibuat:\n";
foreach ($createdIds as $id) {
    echo "    - $id\n";
}

echo "\nSelesai.\n";
