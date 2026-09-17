<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStockBySkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skus' => ['required', 'array', 'min:1', 'max:500'],
            'skus.*' => ['required', 'string', 'max:255', 'distinct'],
            'location_id' => ['nullable', 'uuid'],
            'strategy' => ['nullable', 'in:default,fifo'],
            'require_stock' => ['nullable', 'boolean'],
        ];
    }
}
