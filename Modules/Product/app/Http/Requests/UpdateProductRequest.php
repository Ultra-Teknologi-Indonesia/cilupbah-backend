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
            'category_id' => 'sometimes|required|exists:categories,id',
            'brand_id' => 'sometimes|nullable|exists:brands,id',
            'search_keyword' => 'sometimes|nullable|string',
            'order_type' => 'sometimes|in:REGULER,PREORDER,COD',
            'condition' => 'sometimes|in:NEW,USED',
            'weight' => 'sometimes|nullable|numeric|min:0',
            'length' => 'sometimes|nullable|numeric|min:0',
            'width' => 'sometimes|nullable|numeric|min:0',
            'height' => 'sometimes|nullable|numeric|min:0',
            'is_cod_allowed' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
