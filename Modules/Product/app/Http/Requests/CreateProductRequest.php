<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'name' => 'required|string|max:255',

            'sku' => 'nullable|string|max:50|unique:products,sku',
            'category_id' => 'required|bail|integer|exists:categories,id',
            'description' => 'nullable|string|max:10000',
            'is_bundle' => 'nullable|boolean',
            'is_consignment' => 'nullable|boolean',
            'order_type' => ['nullable', Rule::in(['REGULER', 'PREORDER', 'COD'])],
            'indent_days' => 'nullable|integer|min:0|required_if:order_type,PREORDER',

            'status' => ['nullable', Rule::in(['download', 'master'])],
            'is_active' => 'nullable|boolean',

            'is_stored' => 'nullable|boolean',
            'is_sold' => 'nullable|boolean',
            'is_purchased' => 'nullable|boolean',
            'purchase_lead_time' => 'nullable|integer|min:0',
            'sales_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'revenue')],
            'sales_return_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'revenue')],
            'inventory_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'asset')],
            'cogs_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'expense')],

            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'package_contents' => 'nullable|string|max:2000',

            'media' => 'required|array|min:1|max:10',
            'media.*.media_uuid' => 'required_without:media.*.url|bail|uuid|exists:media,uuid',
            'media.*.url' => 'required_without:media.*.media_uuid|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer|min:0',

            'specifications' => 'nullable|array',
            'specifications.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
            'specifications.*.attribute_option_id' => 'nullable|bail|integer|exists:attribute_options,id',
            'specifications.*.text_value' => 'nullable|string',

            'variation_types' => 'nullable|array|max:2',
            'variation_types.*.attribute_id' => 'nullable|bail|integer|distinct|exists:attributes,id',
            'variation_types.*.name' => 'nullable|string|max:100',
            'variation_types.*.sort_order' => 'nullable|integer|min:0',

            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'required|string|max:50|distinct:ignore_case|unique:product_variants,sku',
            'variants.*.barcode' => 'nullable|string|max:100|distinct:ignore_case|unique:product_variants,barcode',
            'variants.*.sell_price' => 'required|numeric|min:0',
            'variants.*.buy_price' => 'nullable|numeric|min:0',
            'variants.*.sales_tax_id' => 'nullable|integer|exists:taxes,id',
            'variants.*.purchase_tax_id' => 'nullable|integer|exists:taxes,id',
            'variants.*.min_stock' => 'nullable|integer|min:0',
            'variants.*.safe_stock' => 'nullable|integer|min:0',
            'variants.*.is_active' => 'nullable|boolean',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.length' => 'nullable|numeric|min:0',
            'variants.*.width' => 'nullable|numeric|min:0',
            'variants.*.height' => 'nullable|numeric|min:0',

            'variants.*.unlimited_shop_ids' => 'nullable|array',
            'variants.*.unlimited_shop_ids.*' => 'uuid|distinct|exists:channel_shops,id',

            'variants.*.options' => 'nullable|array',
            'variants.*.options.*.attribute_id' => 'nullable|bail|integer|exists:attributes,id',
            'variants.*.options.*.name' => 'nullable|string|max:100',
            'variants.*.options.*.value' => 'required|string',

            'variants.*.media' => 'nullable|array',
            'variants.*.media.*.media_uuid' => 'required_without:variants.*.media.*.url|bail|uuid|exists:media,uuid',
            'variants.*.media.*.url' => 'required_without:variants.*.media.*.media_uuid|string',
            'variants.*.media.*.media_type' => 'nullable|in:image,video',
            'variants.*.media.*.is_primary' => 'nullable|boolean',
            'variants.*.media.*.sort_order' => 'nullable|integer|min:0',

            'variants.*.wholesale_prices' => 'nullable|array',
            'variants.*.wholesale_prices.*.min_qty' => 'required|integer|min:1',
            'variants.*.wholesale_prices.*.price' => 'required|numeric|min:0',
            'variants.*.wholesale_prices.*.customer_type' => 'nullable|string',

            'variants.*.channel_prices' => 'nullable|array',
            'variants.*.channel_prices.*.channel_shop_id' => 'required|uuid|exists:channel_shops,id',
            'variants.*.channel_prices.*.price' => 'required|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {

            foreach ((array) $this->input('variation_types', []) as $i => $vt) {
                if (empty($vt['attribute_id']) && empty($vt['name'])) {
                    $v->errors()->add("variation_types.$i.attribute_id", 'Attribute ID atau nama jenis varian wajib diisi.');
                }
            }

            foreach ((array) $this->input('variants', []) as $vi => $variant) {
                foreach ((array) ($variant['options'] ?? []) as $oi => $opt) {
                    if (empty($opt['attribute_id']) && empty($opt['name'])) {
                        $v->errors()->add("variants.$vi.options.$oi.attribute_id", 'Attribute ID atau nama opsi varian wajib diisi.');
                    }
                }
            }

            $categoryId = $this->input('category_id');
            if ($categoryId !== null && $v->errors()->has('category_id') === false) {
                $repo = app(\Modules\Product\Repositories\CategoryAttributeRepository::class);
                if (! $repo->isLeaf((int) $categoryId)) {
                    $v->errors()->add(
                        'category_id',
                        'Pilih kategori paling spesifik: Kategori → Sub-Kategori → Jenis Produk wajib dipilih.'
                    );
                }
            }

            $media = collect((array) $this->input('media', []))->filter(fn ($m) => is_array($m));
            $images = $media->filter(fn ($m) => ($m['media_type'] ?? 'image') !== 'video');
            $videos = $media->filter(fn ($m) => ($m['media_type'] ?? 'image') === 'video');

            if ($images->isEmpty()) {
                $v->errors()->add('media', 'Minimal 1 foto produk wajib diunggah.');
            }
            if ($images->count() > 9) {
                $v->errors()->add('media', 'Maksimal 9 foto produk.');
            }
            if ($videos->count() > 1) {
                $v->errors()->add('media', 'Maksimal 1 video produk.');
            }

            foreach ((array) $this->input('variants', []) as $i => $variant) {
                $min = $variant['min_stock'] ?? null;
                $safe = $variant['safe_stock'] ?? null;
                if ($min !== null && $safe !== null && (int) $safe < (int) $min) {
                    $v->errors()->add(
                        "variants.$i.safe_stock",
                        'Batas stok aman tidak boleh lebih kecil dari batas stok menipis.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'variation_types.max' => 'Maksimal 2 jenis varian per produk.',
            'variation_types.*.attribute_id.distinct' => 'Jenis varian tidak boleh duplikat.',
            'sku.unique' => 'SKU produk sudah digunakan.',
            'variants.*.sku.unique' => 'SKU varian sudah digunakan.',
            'variants.*.sku.distinct' => 'SKU varian tidak boleh sama antar varian.',
            'variants.*.barcode.unique' => 'Barcode varian sudah digunakan.',
            'sales_account_id.exists' => 'Akun Penjualan harus akun bertipe pendapatan (revenue).',
            'sales_return_account_id.exists' => 'Akun Retur Penjualan harus akun bertipe pendapatan (revenue).',
            'inventory_account_id.exists' => 'Akun Persediaan harus akun bertipe aset (asset).',
            'cogs_account_id.exists' => 'Akun HPP harus akun bertipe beban (expense).',
            'indent_days.required_if' => 'Lama indent wajib diisi untuk produk Pre-Order.',
            'media.required' => 'Minimal 1 foto produk wajib diunggah.',
            'media.min' => 'Minimal 1 foto produk wajib diunggah.',
            'media.max' => 'Maksimal 9 foto dan 1 video produk (total 10 media).',
        ];
    }
}
