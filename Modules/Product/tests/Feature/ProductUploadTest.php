<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Kamera']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Sony']);
        DB::table('attributes')->insertOrIgnore([
            ['id' => 1, 'name' => 'Warna', 'type' => 'sales'],
            ['id' => 2, 'name' => 'Ukuran', 'type' => 'sales'],
            ['id' => 36, 'name' => 'Resolusi', 'type' => 'spec'],
        ]);
    }

    public function test_can_upload_internal_product()
    {
        $payload = [
            "brand_id" => 1,
            "category_id" => 1,
            "name" => "Sony Alpha A6000 Kit 16-50mm",
            "sku" => "SONY-A6000-COPY-" . rand(1000, 9999),
            "description" => "Kamera mirrorless ringan dengan sensor 24.3MP",
            "search_keyword" => "kamera sony, alpha, a6000, mirrorless",
            "order_type" => "PREORDER",
            "indent_days" => 1,
            "weight" => 1.5,
            "length" => 12,
            "width" => 10,
            "height" => 4,
            "condition" => "NEW",
            "is_cod_allowed" => false,
            "danger_level" => 0,
            "is_draft" => false,
            "showcase_id" => null,
            "is_active" => true,
            "specifications" => [
                [
                    "attribute_id" => 36,
                    "attribute_option_id" => null,
                    "text_value" => "24.3 MP"
                ]
            ],
            "media" => [
                [
                    "url" => "https://example.com/image1.jpg",
                    "media_type" => "image",
                    "is_primary" => true,
                    "sort_order" => 1
                ]
            ],
            "variation_types" => [
                ["attribute_id" => 1, "sort_order" => 1],
                ["attribute_id" => 2, "sort_order" => 2]
            ],
            "variants" => [
                [
                    "sku" => "SONY-A6000-BLACK-L-" . rand(1000, 9999),
                    "buy_price" => 5000000,
                    "sell_price" => 6000000,
                    "weight" => 1.2,
                    "length" => 12,
                    "width" => 10,
                    "height" => 4,
                    "is_serial_batch" => false,
                    "is_active" => true,
                    "options" => [
                        ["attribute_id" => 1, "value" => "Black"],
                        ["attribute_id" => 2, "value" => "L"]
                    ],
                    "media" => [
                        [
                            "url" => "https://example.com/variant-black.jpg",
                            "media_type" => "image",
                            "is_primary" => true,
                            "sort_order" => 1
                        ]
                    ],
                    "wholesale_prices" => [
                        [
                            "min_qty" => 10,
                            "price" => 5800000,
                            "customer_type" => "B2B"
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/products', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'product_id'
            ]
        ]);

        $productId = $response->json('data.product_id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Sony Alpha A6000 Kit 16-50mm'
        ]);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $productId,
            'sell_price' => 6000000
        ]);

        $this->assertDatabaseHas('product_media', [
            'product_id' => $productId,
            'url' => 'https://example.com/image1.jpg'
        ]);
    }
}
