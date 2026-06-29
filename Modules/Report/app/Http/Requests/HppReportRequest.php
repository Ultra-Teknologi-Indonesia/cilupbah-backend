<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HppReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ];
    }
}
