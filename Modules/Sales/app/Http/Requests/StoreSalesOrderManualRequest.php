<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\SalesOrder;

class StoreSalesOrderManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salesorder_no'       => ['nullable', 'string', 'max:64'],
            'no_ref'              => ['nullable', 'string', 'max:64'],
            'transaction_date'    => ['required', 'date'],
            'internal_store_id'   => ['required', 'uuid', 'exists:internal_stores,id'],
            'salesman_id'         => ['nullable', 'uuid', 'exists:salesmen,id'],
            'location_id'         => ['required', 'uuid', 'exists:locations,id'],
            'customer_id'         => ['nullable', 'uuid', 'exists:contacts,id'],
            'customer_name'       => ['required', 'string', 'max:180'],
            'note'                => ['nullable', 'string', 'max:1000'],

            'sub_total'           => ['nullable', 'numeric', 'min:0'],
            'total_disc'          => ['nullable', 'numeric', 'min:0'],
            'other_discount'      => ['nullable', 'numeric', 'min:0'],
            'total_tax'           => ['nullable', 'numeric', 'min:0'],
            'shipping_cost'       => ['nullable', 'numeric', 'min:0'],
            'shipping_discount'   => ['nullable', 'numeric', 'min:0'],
            'insurance_cost'      => ['nullable', 'numeric', 'min:0'],
            'service_fee'         => ['nullable', 'numeric', 'min:0'],
            'seller_voucher'      => ['nullable', 'numeric', 'min:0'],
            'order_processing_fee' => ['nullable', 'numeric', 'min:0'],
            'grand_total'         => ['nullable', 'numeric', 'min:0'],
            'price_includes_tax'  => ['nullable', 'boolean'],

            'is_paid'             => ['nullable', 'boolean'],
            'is_cod'              => ['nullable', 'boolean'],

            'delivery_method'     => ['required', Rule::in([
                SalesOrder::DELIVERY_COURIER,
                SalesOrder::DELIVERY_SELF_PICKUP,
            ])],
            'shipping_provider'   => ['nullable', 'string', 'max:64'],
            'tracking_number'     => ['nullable', 'string', 'max:64'],
            'order_weight_gram'   => ['nullable', 'integer', 'min:0'],

            'shipping_full_name'  => ['nullable', 'string', 'max:180'],
            'shipping_phone'      => ['nullable', 'string', 'max:32'],
            'shipping_address'    => ['nullable', 'string', 'max:1000'],
            'shipping_area'       => ['nullable', 'string', 'max:120'],
            'shipping_city'       => ['nullable', 'string', 'max:120'],
            'shipping_province'   => ['nullable', 'string', 'max:120'],
            'shipping_post_code'  => ['nullable', 'string', 'max:16'],
            'shipping_country'    => ['nullable', 'string', 'max:120'],
            'shipping_coordinate' => ['nullable', 'string', 'max:64'],

            'items'                    => ['required', 'array', 'min:1'],
            'items.*.item_id'          => ['required', 'string', 'max:64'],
            'items.*.sku'              => ['required', 'string', 'max:64'],
            'items.*.description'      => ['nullable', 'string', 'max:255'],
            'items.*.qty_in_base'      => ['required', 'integer', 'min:1'],
            'items.*.price'            => ['required', 'numeric', 'min:0'],
            'items.*.disc'             => ['nullable', 'numeric', 'min:0'],
            'items.*.disc_percent'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_amount'       => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $method = $this->input('delivery_method');
            if ($method === SalesOrder::DELIVERY_COURIER && empty($this->input('shipping_provider'))) {
                $v->errors()->add('shipping_provider', 'Kurir wajib dipilih ketika metode pengiriman adalah COURIER.');
            }
        });
    }
}
