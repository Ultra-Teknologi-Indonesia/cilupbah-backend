<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesReturnSettingService;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $conditions = app(SalesReturnSettingService::class)->allowedConditions();

        return [
            'order_id'           => ['nullable', 'bail', 'uuid', 'exists:sales_orders,id'],
            'location_id'        => ['required', 'bail', 'uuid', 'exists:locations,id'],
            'source'             => ['nullable', 'string', 'in:manual,marketplace'],
            'customer_name'      => ['nullable', 'string', 'max:255'],
            'customer_contact'   => ['nullable', 'string', 'max:255'],
            'reason'             => ['nullable', 'string'],
            'notes'              => ['nullable', 'string'],
            'created_by'         => ['required', 'string', 'max:100'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_id'    => ['required', 'bail', 'uuid', 'exists:product_variants,id'],
            'items.*.qty'        => ['required', 'integer', 'min:1'],
            'items.*.condition'  => ['nullable', 'string', Rule::in($conditions)],
            'items.*.notes'      => ['nullable', 'string'],
        ];
    }

    /** Tolak retur yang melewati batas hari sejak transaksi order (bila diatur). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return; // input belum valid (mis. order_id bukan uuid) → jangan query
            }

            $days = app(SalesReturnSettingService::class)->returnValidityDays();
            $orderId = $this->input('order_id');

            if (! $days || ! $orderId) {
                return;
            }

            $order = SalesOrder::find($orderId);
            if ($order && $order->transaction_date && $order->transaction_date->lt(now()->subDays($days))) {
                $validator->errors()->add('order_id', "Retur melewati batas {$days} hari sejak transaksi order.");
            }
        });
    }
}
