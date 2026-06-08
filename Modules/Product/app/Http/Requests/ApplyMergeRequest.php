<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'master_name' => 'required|string|max:200',
            'product_ids' => 'required|array|min:2',
            'product_ids.*' => 'uuid',
        ];
    }
}
