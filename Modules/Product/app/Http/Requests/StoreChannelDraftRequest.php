<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChannelDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shop_id' => 'required|string',
            'channel_category_id' => 'nullable|string',
            'attribute_mapping' => 'nullable|array',
            'price_override' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,ready,cancelled',
        ];
    }
}
