<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueueStockReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['required', 'uuid', 'distinct', 'exists:product_variants,id'],
        ];
    }
}
