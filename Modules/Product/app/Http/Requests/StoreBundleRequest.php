<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            'components.*.variant_id' => [
                'required',
                'uuid',
                Rule::exists('product_variants', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'components.*.qty' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'components.*.variant_id.exists' => 'Varian komponen tidak ditemukan atau tidak aktif.',
        ];
    }
}
