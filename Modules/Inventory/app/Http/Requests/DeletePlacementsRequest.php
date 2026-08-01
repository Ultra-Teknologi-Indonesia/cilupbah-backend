<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePlacementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.placement_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ];
    }
}
