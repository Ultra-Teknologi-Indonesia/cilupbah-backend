<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
