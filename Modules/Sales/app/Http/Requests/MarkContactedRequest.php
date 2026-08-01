<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkContactedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => 'nullable|string|in:marketplace_chat,whatsapp,phone,other',
            'note'    => 'nullable|string|max:500',
        ];
    }
}
