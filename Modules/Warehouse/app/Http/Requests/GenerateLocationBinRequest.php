<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'floor_code' => 'required|string|max:10',
            'qty_floor' => 'required|integer|min:1',
            'row_code' => 'required|string|max:10',
            'qty_row' => 'required|integer|min:1',
            'column_code' => 'required|string|max:10',
            'qty_column' => 'required|integer|min:1',
            'bin_code' => 'required|string|max:10',
            'qty_bin' => 'required|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $qtyFloor = (int) $this->input('qty_floor', 1);
            $qtyRow = (int) $this->input('qty_row', 1);
            $qtyColumn = (int) $this->input('qty_column', 1);
            $qtyBin = (int) $this->input('qty_bin', 1);

            $totalCombinations = $qtyFloor * $qtyRow * $qtyColumn * $qtyBin;

            if ($totalCombinations > 2000) {
                $validator->errors()->add(
                    'total_combinations',
                    "Maksimum Kombinasi Rak Adalah 2.000. Anda mencoba membuat {$totalCombinations} kombinasi."
                );
            }
        });
    }
}
