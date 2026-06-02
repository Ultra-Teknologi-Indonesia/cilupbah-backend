<?php

$payload = [
    "brand_id" => 1,
    "category_id" => 1,
    "name" => "Canon EOS R5 Mirrorless Camera",
    "sku" => "CANON-R5-COPY-" . rand(1000, 9999),
    "description" => "Kamera mirrorless full-frame revolusioner dengan video 8K",
    "search_keyword" => "kamera canon, eos r5, mirrorless full-frame",
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
            "attribute_id" => 1,
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
            "sku" => "CANON-R5-BLACK-L-" . rand(1000, 9999),
            "barcode" => "899" . rand(100000000, 999999999),
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

$request = Modules\Product\Http\Requests\CreateProductRequest::create('/api/v1/products', 'POST', $payload);
$app = app();
$request->setContainer($app);
$request->setRedirector($app->make(\Illuminate\Routing\Redirector::class));
$request->validateResolved();

$controller = $app->make(\Modules\Product\Http\Controllers\ProductController::class);
$response = $controller->store($request);

echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";
