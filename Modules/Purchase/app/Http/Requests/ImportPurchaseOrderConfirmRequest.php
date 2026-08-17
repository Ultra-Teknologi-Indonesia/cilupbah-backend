<?php

namespace Modules\Purchase\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportPurchaseOrderConfirmRequest extends FormRequest
{
    use ApiResponse;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'preview_token.required' => 'Token preview wajib disertakan.',
            'preview_token.string'   => 'Token preview harus berupa string.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            $this->errorResponse(
                $validator->errors()->first(),
                422,
                $validator->errors()->toArray(),
                'Validasi Gagal'
            )
        );
    }
}
