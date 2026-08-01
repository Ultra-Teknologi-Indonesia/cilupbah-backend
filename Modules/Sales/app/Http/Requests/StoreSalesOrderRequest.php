<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salesorder_no'       => 'nullable|string',
            'channel_order_no'    => 'nullable|string',
            'channel_shop_id'     => 'nullable|string',
            'customer_name'       => 'required|string|max:255',
            'transaction_date'    => 'nullable|date',
            'sub_total'           => 'nullable|numeric|min:0',
            'total_disc'          => 'nullable|numeric|min:0',
            'total_tax'           => 'nullable|numeric|min:0',
            'shipping_cost'       => 'nullable|numeric|min:0',
            'insurance_cost'      => 'nullable|numeric|min:0',
            'grand_total'         => 'nullable|numeric|min:0',
            'shipping_full_name'  => 'nullable|string|max:255',
            'shipping_phone'      => 'nullable|string|max:50',
            'shipping_address'    => 'nullable|string',
            'shipping_area'       => 'nullable|string|max:255',
            'shipping_city'       => 'nullable|string|max:255',
            'shipping_province'   => 'nullable|string|max:255',
            'shipping_post_code'  => 'nullable|string|max:20',
            'shipping_country'    => 'nullable|string|max:100',
            'payment_method'      => 'nullable|string|max:100',
            'payment_method_name' => 'nullable|string|max:255',
            'source'              => 'nullable|string|max:50',
            'buyer_message'       => 'nullable|string',
            'seller_note'         => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.sku'         => 'required|string',
            'items.*.description' => 'nullable|string',
            'items.*.qty_in_base' => 'required|integer|min:1',
            'items.*.price'       => 'required|numeric|min:0',
            'items.*.disc'        => 'nullable|numeric|min:0',
            'items.*.disc_amount' => 'nullable|numeric|min:0',
            'items.*.tax_amount'  => 'nullable|numeric|min:0',
            'items.*.amount'      => 'nullable|numeric|min:0',
        ];
    }
}
