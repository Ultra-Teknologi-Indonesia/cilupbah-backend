<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bins' => 'required|array|min:1',
            'bins.*.id' => 'required|uuid',
            'bins.*.bin_final_code' => 'required|string|max:255',
            'bins.*.is_stock_acknowledged' => 'required|boolean',
            'bins.*.is_large_bin' => 'required|boolean',
            'bins.*.category' => 'nullable|string|max:255',
        ];
    }
}
