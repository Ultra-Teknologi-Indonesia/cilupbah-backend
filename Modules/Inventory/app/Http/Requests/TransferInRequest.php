<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_by'           => ['nullable', 'string', 'max:100'],
            'assigned_to'           => ['nullable', 'string', 'max:100'],
            'items'                 => ['nullable', 'array'],
            'items.*.item_id'       => ['required_with:items', 'string'],
            'items.*.received_qty'  => ['required_with:items', 'integer', 'min:0'],
            'items.*.rejected_qty'  => ['nullable', 'integer', 'min:0'],
            'items.*.condition'     => ['nullable', 'string', 'in:GOOD,DAMAGED,REJECTED'],
            'items.*.notes'         => ['nullable', 'string'],
        ];
    }
}
