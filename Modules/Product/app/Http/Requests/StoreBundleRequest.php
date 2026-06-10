<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|uuid|exists:products,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category_id' => 'required|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'components' => 'required|array|min:1',
            'components.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'components.*.qty' => 'required|integer|min:1',
        ];
    }
}
