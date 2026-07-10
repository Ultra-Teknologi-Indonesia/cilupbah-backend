<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCourierPickupRequest extends FormRequest
{
    public function authorize(): bool
    {

        return true;
    }

    public function rules(): array
    {

        return [
            'courier_name'  => ['nullable', 'string', 'max:255'],
            'courier_phone' => ['nullable', 'string', 'max:32'],
            'pickup_code'   => ['nullable', 'string', 'max:64'],
        ];
    }
}
