<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePutawayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('inbound_id') && ! $this->filled('inbound_ids')) {
            $this->merge(['inbound_ids' => [$this->input('inbound_id')]]);
        }
    }

    public function rules(): array
    {
        return [
            'inbound_ids' => 'required|array|min:1',
            'inbound_ids.*' => 'required|string|distinct|exists:inbounds,id',
            'assigned_to' => 'nullable|string|exists:users,id',
        ];
    }
}
