<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteOrdersDirectlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|bail|uuid|exists:sales_orders,id',

            'allocations'                => 'sometimes|array',
            'allocations.*.item_id'      => 'required|bail|uuid|exists:product_variants,id',
            'allocations.*.bins'         => 'required|array|min:1',
            'allocations.*.bins.*.bin_id' => 'required|bail|uuid|exists:location_bins,id',
            'allocations.*.bins.*.qty'   => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'allocations.*.bins.required' => 'Setiap SKU harus punya minimal satu rak asal.',
            'allocations.*.bins.*.qty.min' => 'Jumlah per rak harus lebih dari nol.',
        ];
    }
}
