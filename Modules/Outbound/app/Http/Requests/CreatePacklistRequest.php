<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|string|exists:orders,id',
            'location_id' => 'required|string|exists:locations,id',
            'packer_id' => 'nullable|string|exists:users,id',
            'picklist_id' => 'nullable|string|exists:picklists,id',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
