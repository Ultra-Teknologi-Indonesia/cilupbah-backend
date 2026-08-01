<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBinTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transfer_date' => 'nullable|date',
            'created_by' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }
}
