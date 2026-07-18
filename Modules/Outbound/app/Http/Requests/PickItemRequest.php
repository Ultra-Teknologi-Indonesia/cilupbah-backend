<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PickItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty_delta' => 'nullable|integer|min:1',
            'qty_picked' => 'nullable|integer|min:0',
            'bin_code' => 'required|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasDelta = $this->input('qty_delta') !== null;
            $hasAbsolute = $this->input('qty_picked') !== null;

            if (! $hasDelta && ! $hasAbsolute) {
                $validator->errors()->add('qty_delta', 'Wajib mengirim qty_delta (scan) atau qty_picked (koreksi).');
            }

            if ($hasDelta && $hasAbsolute) {
                $validator->errors()->add('qty_delta', 'Kirim salah satu saja: qty_delta atau qty_picked.');
            }
        });
    }
}
