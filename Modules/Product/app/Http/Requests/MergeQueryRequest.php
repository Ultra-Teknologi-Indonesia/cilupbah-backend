<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => 'nullable|string',
        ];
    }

    public function search(): string
    {
        return (string) ($this->validated()['q'] ?? '');
    }
}
