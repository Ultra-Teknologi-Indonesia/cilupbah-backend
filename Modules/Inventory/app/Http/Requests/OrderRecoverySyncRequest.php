<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderRecoverySyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['order', 'awb', 'label', 'all'])],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.reference' => ['required', 'string', 'max:128', 'distinct'],
            'items.*.channel' => ['nullable', Rule::in(['shopee', 'tiktok', 'lazada', 'woocommerce'])],
            'items.*.shop_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
