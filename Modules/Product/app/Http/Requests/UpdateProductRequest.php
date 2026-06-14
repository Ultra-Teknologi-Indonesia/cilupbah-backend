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

            // Mengirim `media` akan MENGGANTI seluruh koleksi gambar/video level produk.
            'media' => 'sometimes|array',
            'media.*.media_uuid' => 'required_without:media.*.url|bail|uuid|exists:media,uuid',
            'media.*.url' => 'required_without:media.*.media_uuid|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer',

            'variants' => 'sometimes|array|min:1',
            'variants.*.sku' => 'required_with:variants|string|max:255',
            'variants.*.sell_price' => 'sometimes|numeric|min:0',
            'variants.*.is_active' => 'sometimes|boolean',
            'variants.*.channel_prices' => 'sometimes|nullable|array',
            'variants.*.channel_prices.*.channel_shop_id' => 'required_with:variants.*.channel_prices|uuid',
            'variants.*.channel_prices.*.price' => 'required_with:variants.*.channel_prices|numeric|min:0',

            // Mengirim `media` pada sebuah varian akan MENGGANTI seluruh media varian itu.
            'variants.*.media' => 'sometimes|array',
            'variants.*.media.*.media_uuid' => 'required_without:variants.*.media.*.url|bail|uuid|exists:media,uuid',
            'variants.*.media.*.url' => 'required_without:variants.*.media.*.media_uuid|string',
            'variants.*.media.*.media_type' => 'nullable|in:image,video',
            'variants.*.media.*.is_primary' => 'nullable|boolean',
            'variants.*.media.*.sort_order' => 'nullable|integer',
        ];
    }
}
