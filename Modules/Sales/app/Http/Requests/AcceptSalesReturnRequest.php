<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'processed_by'         => 'required|string|max:100',
            'items'                => 'sometimes|array',
            'items.*.item_id'      => 'required_with:items|string',
            'items.*.approved_qty' => 'nullable|integer|min:0',
        ];
    }
}
