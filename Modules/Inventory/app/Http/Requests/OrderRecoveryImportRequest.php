<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderRecoveryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:2048'],
            'action' => ['required', Rule::in(['order', 'awb', 'label', 'all'])],
            'channel' => ['nullable', Rule::in(['shopee', 'tiktok', 'lazada', 'woocommerce'])],
            'shop_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
