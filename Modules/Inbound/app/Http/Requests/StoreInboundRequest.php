<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'in:PURCHASE_ORDER,SALES_RETURN,TRANSIT_IN'],
            'expected_date' => ['required', 'date'],
            'created_by' => ['required', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.expected_qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
