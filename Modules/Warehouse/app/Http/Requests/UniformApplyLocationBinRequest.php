<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UniformApplyLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['selected', 'all'])],
            'ids' => 'required_if:scope,selected|array',
            'ids.*' => 'uuid',
            'values' => 'required|array',
            'values.is_stock_acknowledged' => 'nullable|boolean',
            'values.is_large_bin' => 'nullable|boolean',
            'values.category' => 'nullable|string|max:255',
            'values.zone_id' => 'nullable|bail|uuid|exists:location_zones,id',
        ];
    }
}
