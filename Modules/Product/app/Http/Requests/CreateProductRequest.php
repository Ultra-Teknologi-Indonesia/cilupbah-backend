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
            'brand_id' => 'nullable|bail|integer|exists:brands,id',
            'category_id' => 'required|bail|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'is_bundle' => 'nullable|boolean',
            'is_consignment' => 'nullable|boolean',
            
            'specifications' => 'nullable|array',
            'specifications.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
            'specifications.*.attribute_option_id' => 'nullable|bail|integer|exists:attribute_options,id',
            'specifications.*.text_value' => 'nullable|string',
            
            'media' => 'nullable|array',
            'media.*.url' => 'required|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer',
            
            'variation_types' => 'nullable|array',
            'variation_types.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
            'variation_types.*.sort_order' => 'nullable|integer',
            
            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'required|string|max:255|unique:product_variants,sku',
            'variants.*.sell_price' => 'required|numeric|min:0',
            'variants.*.is_active' => 'nullable|boolean',
            'variants.*.channel_prices' => 'nullable|array',
            'variants.*.channel_prices.*.channel_shop_id' => 'required|uuid',
            'variants.*.channel_prices.*.price' => 'required|numeric|min:0',
            
            'variants.*.options' => 'nullable|array',
            'variants.*.options.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
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
