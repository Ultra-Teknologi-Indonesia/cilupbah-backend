<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'category_id' => 'sometimes|required|bail|integer|exists:categories,id',
            'brand_id' => 'sometimes|nullable|bail|integer|exists:brands,id',
            'search_keyword' => 'sometimes|nullable|string',
            'order_type' => 'sometimes|in:REGULER,PREORDER,COD',
            'condition' => 'sometimes|in:NEW,USED',
            'weight' => 'sometimes|nullable|numeric|min:0',
            'length' => 'sometimes|nullable|numeric|min:0',
            'width' => 'sometimes|nullable|numeric|min:0',
            'height' => 'sometimes|nullable|numeric|min:0',
            'is_cod_allowed' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            
            'variants' => 'sometimes|array|min:1',
            'variants.*.sku' => 'required_with:variants|string|max:255',
            'variants.*.sell_price' => 'sometimes|numeric|min:0',
            'variants.*.is_active' => 'sometimes|boolean',
            'variants.*.channel_prices' => 'sometimes|nullable|array',
            'variants.*.channel_prices.*.channel_shop_id' => 'required_with:variants.*.channel_prices|uuid',
            'variants.*.channel_prices.*.price' => 'required_with:variants.*.channel_prices|numeric|min:0',
        ];
    }
}
