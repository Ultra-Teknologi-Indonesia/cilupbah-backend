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

    protected function prepareForValidation(): void
    {
        $filter = is_array($this->input('filter')) ? $this->input('filter') : [];

        $this->merge([
            'location_id' => $this->input('location_id', $filter['location_id'] ?? null),
            'contact_id' => $this->input('contact_id', $filter['contact_id'] ?? null),
            'status' => $this->input('status', $filter['status'] ?? null),
            'date_from' => $this->input('date_from', $filter['date_from'] ?? null),
            'date_to' => $this->input('date_to', $filter['date_to'] ?? null),
        ]);
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
