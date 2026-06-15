<?php

namespace Modules\Channel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => 'sometimes|boolean',
            'order_sync_enabled' => 'sometimes|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->has('is_active') && ! $this->has('order_sync_enabled')) {
                $v->errors()->add('is_active', 'Minimal satu dari is_active / order_sync_enabled wajib diisi.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'is_active.boolean' => 'is_active harus boolean.',
            'order_sync_enabled.boolean' => 'order_sync_enabled harus boolean.',
        ];
    }
}
