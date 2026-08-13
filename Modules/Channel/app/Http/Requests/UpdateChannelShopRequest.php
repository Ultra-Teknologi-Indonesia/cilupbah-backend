<?php

namespace Modules\Channel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'is_shadow_mode' => 'sometimes|boolean',
            'catalog_pull_enabled' => 'sometimes|boolean',
            'catalog_push_enabled' => 'sometimes|boolean',
            'stock_push_enabled' => 'sometimes|boolean',
            'fulfillment_push_enabled' => 'sometimes|boolean',
            'handover_method' => 'sometimes|in:dropoff,pickup',
            'stock_source_mode' => 'sometimes|in:location,total',
            'location_id' => [
                'nullable',
                'required_if:stock_source_mode,location',
                'uuid',
                Rule::exists('locations', 'id')->where(fn ($q) => $q
                    ->where('is_warehouse', true)
                    ->where('is_active', true)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $fields = ['is_active', 'order_sync_enabled', 'is_shadow_mode', 'catalog_pull_enabled', 'catalog_push_enabled', 'stock_push_enabled', 'fulfillment_push_enabled', 'handover_method', 'stock_source_mode', 'location_id'];
            if (collect($fields)->every(fn ($f) => ! $this->has($f))) {
                $v->errors()->add('is_active', 'Minimal satu pengaturan wajib diisi.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'is_active.boolean' => 'is_active harus boolean.',
            'order_sync_enabled.boolean' => 'order_sync_enabled harus boolean.',
            'is_shadow_mode.boolean' => 'is_shadow_mode harus boolean.',
            'stock_source_mode.in' => 'stock_source_mode harus location atau total.',
            'location_id.required_if' => 'Gudang wajib dipilih saat mode location.',
            'location_id.exists' => 'Gudang tidak ditemukan atau tidak aktif.',
        ];
    }
}
