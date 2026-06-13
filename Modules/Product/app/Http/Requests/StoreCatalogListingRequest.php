<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|uuid|exists:products,id',
            'shop_id' => 'required|string',
            'channel_category_id' => 'nullable|string',
            'attribute_mapping' => 'nullable|array',
            'price_override' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,ready,cancelled',
        ];
    }
}
