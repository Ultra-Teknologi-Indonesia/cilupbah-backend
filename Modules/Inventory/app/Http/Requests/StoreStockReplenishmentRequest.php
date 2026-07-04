<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            'to_location_id'   => ['nullable', 'uuid', 'exists:locations,id'],
            'note'             => ['nullable', 'string', 'max:1000'],

            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_id'    => ['required', 'uuid'],
            'items.*.sku'        => ['required', 'string', 'max:64'],
            'items.*.qty'        => ['required', 'integer', 'min:1'],
            'items.*.reason'     => ['nullable', 'string', 'max:500'],
        ];
    }
}
