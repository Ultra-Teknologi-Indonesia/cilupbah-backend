<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutoMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_pattern_groups' => 'sometimes|array',
            'name_pattern_groups.*' => 'array|min:1',
            'name_pattern_groups.*.*' => 'string',
        ];
    }
}
