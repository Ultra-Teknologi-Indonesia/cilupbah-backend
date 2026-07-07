<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PenyesuaianStokPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'after_or_equal:start_date'],
            'product_ids'     => ['nullable', 'array'],
            'product_ids.*'   => ['uuid', 'exists:product_variants,id'],
            'location_ids'    => ['nullable', 'array'],
            'location_ids.*'  => ['uuid', 'exists:locations,id'],
            'download'        => ['nullable', 'boolean'],
        ];
    }
}
