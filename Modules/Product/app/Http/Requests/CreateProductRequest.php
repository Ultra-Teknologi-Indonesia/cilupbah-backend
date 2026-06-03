<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "CreateProductRequest",
    required: ["category_id", "name", "variants"],
    properties: [
        new OA\Property(property: "brand_id", type: "integer", example: 101, nullable: true),
        new OA\Property(property: "category_id", type: "integer", example: 54),
        new OA\Property(property: "name", type: "string", example: "Sony Alpha A6000 Kit 16-50mm"),
        new OA\Property(property: "sku", type: "string", example: "SONY-A6000-COPY-1234", nullable: true),
        new OA\Property(property: "description", type: "string", example: "Kamera mirrorless ringan dengan sensor 24.3MP", nullable: true),
        new OA\Property(property: "search_keyword", type: "string", example: "kamera sony, alpha, a6000, mirrorless", nullable: true),
        new OA\Property(property: "order_type", type: "string", enum: ["REGULER", "PREORDER", "COD"], example: "PREORDER", nullable: true),
        new OA\Property(property: "indent_days", type: "integer", example: 1, nullable: true),
        new OA\Property(property: "weight", type: "number", format: "float", example: 1.5, nullable: true),
        new OA\Property(property: "length", type: "number", format: "float", example: 12.0, nullable: true),
        new OA\Property(property: "width", type: "number", format: "float", example: 10.0, nullable: true),
        new OA\Property(property: "height", type: "number", format: "float", example: 4.0, nullable: true),
        new OA\Property(property: "condition", type: "string", enum: ["NEW", "USED"], example: "NEW", nullable: true),
        new OA\Property(property: "is_cod_allowed", type: "boolean", example: false, nullable: true),
        new OA\Property(property: "danger_level", type: "integer", example: 0, nullable: true),
        new OA\Property(property: "is_draft", type: "boolean", example: false, nullable: true),
        new OA\Property(property: "showcase_id", type: "integer", example: null, nullable: true),
        new OA\Property(property: "is_active", type: "boolean", example: true, nullable: true),
        new OA\Property(
            property: "specifications",
            type: "array",
            items: new OA\Items(
                properties: [
                    new OA\Property(property: "attribute_id", type: "integer", example: 36),
                    new OA\Property(property: "attribute_option_id", type: "integer", example: null, nullable: true),
                    new OA\Property(property: "text_value", type: "string", example: "24.3 MP", nullable: true)
                ],
                type: "object"
            ),
            nullable: true
        ),
        new OA\Property(
            property: "media",
            type: "array",
            items: new OA\Items(
                properties: [
                    new OA\Property(property: "url", type: "string", example: "https://example.com/image1.jpg"),
                    new OA\Property(property: "media_type", type: "string", enum: ["image", "video"], example: "image", nullable: true),
                    new OA\Property(property: "is_primary", type: "boolean", example: true, nullable: true),
                    new OA\Property(property: "sort_order", type: "integer", example: 1, nullable: true)
                ],
                type: "object"
            ),
            nullable: true
        ),
        new OA\Property(
            property: "variation_types",
            type: "array",
            items: new OA\Items(
                properties: [
                    new OA\Property(property: "attribute_id", type: "integer", example: 1),
                    new OA\Property(property: "sort_order", type: "integer", example: 1, nullable: true)
                ],
                type: "object"
            ),
            nullable: true
        ),
        new OA\Property(
            property: "variants",
            type: "array",
            items: new OA\Items(
                required: ["sku", "sell_price"],
                properties: [
                    new OA\Property(property: "sku", type: "string", example: "SONY-A6000-BLACK-L-1234"),
                    new OA\Property(property: "barcode", type: "string", example: "899123456789", nullable: true),
                    new OA\Property(property: "buy_price", type: "number", format: "float", example: 5000000, nullable: true),
                    new OA\Property(property: "sell_price", type: "number", format: "float", example: 6000000),
                    new OA\Property(property: "weight", type: "number", format: "float", example: 1.2, nullable: true),
                    new OA\Property(property: "length", type: "number", format: "float", example: 12.0, nullable: true),
                    new OA\Property(property: "width", type: "number", format: "float", example: 10.0, nullable: true),
                    new OA\Property(property: "height", type: "number", format: "float", example: 4.0, nullable: true),
                    new OA\Property(property: "is_serial_batch", type: "boolean", example: false, nullable: true),
                    new OA\Property(property: "is_active", type: "boolean", example: true, nullable: true),
                    new OA\Property(
                        property: "options",
                        type: "array",
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: "attribute_id", type: "integer", example: 1),
                                new OA\Property(property: "value", type: "string", example: "Black")
                            ],
                            type: "object"
                        ),
                        nullable: true
                    ),
                    new OA\Property(
                        property: "media",
                        type: "array",
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: "url", type: "string", example: "https://example.com/variant-black.jpg"),
                                new OA\Property(property: "media_type", type: "string", enum: ["image", "video"], example: "image", nullable: true),
                                new OA\Property(property: "is_primary", type: "boolean", example: true, nullable: true),
                                new OA\Property(property: "sort_order", type: "integer", example: 1, nullable: true)
                            ],
                            type: "object"
                        ),
                        nullable: true
                    ),
                    new OA\Property(
                        property: "wholesale_prices",
                        type: "array",
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: "min_qty", type: "integer", example: 10),
                                new OA\Property(property: "price", type: "number", format: "float", example: 5800000),
                                new OA\Property(property: "customer_type", type: "string", example: "B2B", nullable: true)
                            ],
                            type: "object"
                        ),
                        nullable: true
                    )
                ],
                type: "object"
            )
        )
    ]
)]
class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'description' => 'nullable|string',
            'search_keyword' => 'nullable|string',
            'order_type' => 'nullable|in:REGULER,PREORDER,COD',
            'indent_days' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'condition' => 'nullable|in:NEW,USED',
            'is_cod_allowed' => 'nullable|boolean',
            'danger_level' => 'nullable|integer|min:0',
            'is_draft' => 'nullable|boolean',
            'showcase_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            
            'specifications' => 'nullable|array',
            'specifications.*.attribute_id' => 'required|exists:attributes,id',
            'specifications.*.attribute_option_id' => 'nullable|exists:attribute_options,id',
            'specifications.*.text_value' => 'nullable|string',
            
            'media' => 'nullable|array',
            'media.*.url' => 'required|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer',
            
            'variation_types' => 'nullable|array',
            'variation_types.*.attribute_id' => 'required|exists:attributes,id',
            'variation_types.*.sort_order' => 'nullable|integer',
            
            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'required|string|max:255|unique:product_variants,sku',
            'variants.*.barcode' => 'nullable|string|max:255|unique:product_variants,barcode',
            'variants.*.buy_price' => 'nullable|numeric|min:0',
            'variants.*.sell_price' => 'required|numeric|min:0',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.length' => 'nullable|numeric|min:0',
            'variants.*.width' => 'nullable|numeric|min:0',
            'variants.*.height' => 'nullable|numeric|min:0',
            'variants.*.is_serial_batch' => 'nullable|boolean',
            'variants.*.is_active' => 'nullable|boolean',
            
            'variants.*.options' => 'nullable|array',
            'variants.*.options.*.attribute_id' => 'required|exists:attributes,id',
            'variants.*.options.*.value' => 'required|string',
            
            'variants.*.media' => 'nullable|array',
            'variants.*.media.*.url' => 'required|string',
            'variants.*.media.*.media_type' => 'nullable|in:image,video',
            'variants.*.media.*.is_primary' => 'nullable|boolean',
            'variants.*.media.*.sort_order' => 'nullable|integer',
            
            'variants.*.wholesale_prices' => 'nullable|array',
            'variants.*.wholesale_prices.*.min_qty' => 'required|integer|min:1',
            'variants.*.wholesale_prices.*.price' => 'required|numeric|min:0',
            'variants.*.wholesale_prices.*.customer_type' => 'nullable|string',
        ];
    }
}
