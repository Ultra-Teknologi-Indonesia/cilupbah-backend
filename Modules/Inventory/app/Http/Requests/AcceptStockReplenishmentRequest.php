<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptStockReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'note'             => ['nullable', 'string', 'max:1000'],
        ];
    }
}
