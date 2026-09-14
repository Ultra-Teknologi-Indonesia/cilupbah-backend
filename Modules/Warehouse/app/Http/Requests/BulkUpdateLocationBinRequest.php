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

            'bins' => 'required|array|min:1|max:200',
            'bins.*.id' => 'required|uuid|distinct',
            'bins.*.bin_final_code' => 'required|string|max:255|distinct',
            'bins.*.is_stock_acknowledged' => 'required|boolean',
            'bins.*.is_large_bin' => 'required|boolean',
            'bins.*.category' => 'sometimes|nullable|string|max:255',
        ];
    }
}
