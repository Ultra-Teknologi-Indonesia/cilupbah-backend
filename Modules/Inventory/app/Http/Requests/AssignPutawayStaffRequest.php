<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPutawayStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data' => 'required|array|min:1',
            'data.*.putaway_id' => 'required|string|exists:putaways,id',
            'data.*.assigned_to' => 'required|integer|exists:users,id',
            'performed_by' => 'required|string|max:100',
        ];
    }
}
