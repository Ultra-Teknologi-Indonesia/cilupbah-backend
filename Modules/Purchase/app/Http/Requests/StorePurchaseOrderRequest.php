<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $poUnique = $this->route('id')
            ? 'unique:purchase_orders,po_number,' . $this->route('id')
            : 'unique:purchase_orders,po_number';

        return [
            'po_number'              => ['nullable', 'string', 'max:50', $poUnique],
            'contact_id'             => ['required', 'string', 'exists:contacts,id'],
            'location_id'            => ['required', 'string', 'exists:locations,id'],
            'order_date'             => ['required', 'date'],
            'expected_date'          => ['nullable', 'date', 'after_or_equal:order_date'],
            'ref_no'                 => ['nullable', 'string', 'max:100'],
            'payment_term'           => ['nullable', 'integer', 'min:0'],
            'is_tax_included'        => ['nullable', 'boolean'],
            'notes'                  => ['nullable', 'string'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.item_id'        => ['required', 'string', 'exists:product_variants,id'],
            'items.*.description'    => ['nullable', 'string'],
            'items.*.unit'           => ['nullable', 'string', 'max:30'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.disc'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_id'         => ['nullable', 'string', 'exists:taxes,id'],
        ];
    }

    public function messages(): array
    {
        $messages = [];
        $items = $this->input('items', []);

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                // Gunakan sku atau name jika dikirimkan oleh frontend, jika tidak gunakan fallback baris
                $sku = $item['sku'] ?? null;
                $name = $item['name'] ?? null;
                
                $identifier = 'baris ke-' . ($index + 1);
                if ($sku) {
                    $identifier = "SKU {$sku}";
                } elseif ($name) {
                    $identifier = "{$name}";
                }

                $messages["items.{$index}.item_id.exists"]   = "Produk ({$identifier}) tidak valid atau tidak ditemukan di database.";
                $messages["items.{$index}.item_id.required"] = "Produk ({$identifier}) wajib diisi.";
                $messages["items.{$index}.qty.required"]     = "Kuantitas untuk produk ({$identifier}) wajib diisi.";
                $messages["items.{$index}.qty.min"]          = "Kuantitas untuk produk ({$identifier}) minimal :min.";
                $messages["items.{$index}.unit_price.required"] = "Harga satuan untuk produk ({$identifier}) wajib diisi.";
                $messages["items.{$index}.unit_price.min"]   = "Harga satuan untuk produk ({$identifier}) tidak boleh negatif.";
            }
        }

        // Fallback messages
        $messages['items.*.item_id.exists'] = 'Produk pada baris ke-:position tidak valid atau tidak ditemukan di database.';
        $messages['items.*.item_id.required'] = 'Produk pada baris ke-:position wajib diisi.';
        $messages['items.*.qty.required'] = 'Kuantitas pada baris ke-:position wajib diisi.';
        $messages['items.*.qty.min'] = 'Kuantitas pada baris ke-:position minimal :min.';
        $messages['items.*.unit_price.required'] = 'Harga satuan pada baris ke-:position wajib diisi.';
        $messages['items.*.unit_price.min'] = 'Harga satuan pada baris ke-:position tidak boleh negatif.';

        return $messages;
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => 'Terdapat kesalahan pada input form pesanan. Silakan periksa kembali produk yang Anda pilih.',
            'errors'  => $validator->errors()
        ], 422));
    }
}
