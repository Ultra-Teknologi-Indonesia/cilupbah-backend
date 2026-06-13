<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashbankIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transactionDateFrom' => ['nullable', 'date'],
            'transactionDateTo' => ['nullable', 'date', 'after_or_equal:transactionDateFrom'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
