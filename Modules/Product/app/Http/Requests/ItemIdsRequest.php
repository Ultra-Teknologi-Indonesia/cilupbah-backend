<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi daftar id produk untuk endpoint all-stocks & prices.
 */
class ItemIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|uuid',
        ];
    }
}
