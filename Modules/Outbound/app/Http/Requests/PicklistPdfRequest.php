<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PicklistPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|string|exists:picklists,id',
        ];
    }

    public function validationData(): array
    {
        return array_merge($this->all(), $this->route()->parameters());
    }
}
