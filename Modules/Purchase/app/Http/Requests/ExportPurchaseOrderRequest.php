<?php

namespace Modules\Purchase\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ExportPurchaseOrderRequest extends FormRequest
{
    use ApiResponse;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'string', 'max:50'],
            'contact_id'  => ['nullable', 'string', 'max:50'],
            'status'      => ['nullable', 'string', 'max:50'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'search'      => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            $this->errorResponse(
                $validator->errors()->first(),
                422,
                $validator->errors()->toArray(),
                'Parameter Export Tidak Valid'
            )
        );
    }
}
