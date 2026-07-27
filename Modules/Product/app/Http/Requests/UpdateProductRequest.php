<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'sku' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('products', 'sku')->ignore($this->route('product'), 'id'),
            ],
            'description' => 'sometimes|nullable|string',
            'category_id' => 'sometimes|required|bail|integer|exists:categories,id',
            'search_keyword' => 'sometimes|nullable|string',
            'order_type' => 'sometimes|in:REGULER,PREORDER,COD',
            'indent_days' => 'sometimes|nullable|integer|min:0|required_if:order_type,PREORDER',
            'condition' => 'sometimes|in:NEW,USED',
            'status' => ['sometimes', Rule::in(['download', 'master'])],
            'is_bundle' => 'sometimes|boolean',
            'is_consignment' => 'sometimes|boolean',
            'weight' => 'sometimes|nullable|numeric|min:0',
            'package_contents' => 'sometimes|nullable|string|max:2000',
            'is_cod_allowed' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',

            'is_stored' => 'sometimes|boolean',
            'is_sold' => 'sometimes|boolean',
            'is_purchased' => 'sometimes|boolean',
            'purchase_lead_time' => 'sometimes|nullable|integer|min:0',
            'sales_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'revenue')],
            'sales_return_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'revenue')],
            'inventory_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'asset')],
            'cogs_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('account_type', 'expense')],

            'media' => 'sometimes|array|min:1|max:10',
            'media.*.media_uuid' => 'required_without:media.*.url|bail|uuid|exists:media,uuid',
            'media.*.url' => 'required_without:media.*.media_uuid|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer',

            'variation_types' => 'sometimes|nullable|array|max:2',
            'variation_types.*.attribute_id' => 'nullable|bail|integer|distinct',
            'variation_types.*.name' => 'nullable|string|max:100',
            'variation_types.*.sort_order' => 'nullable|integer|min:0',

            'specifications' => 'sometimes|nullable|array',
            'specifications.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
            'specifications.*.attribute_option_id' => 'nullable|bail|integer|exists:attribute_options,id',
            'specifications.*.text_value' => 'nullable|string',

            'variants' => 'sometimes|array|min:1',

            'variants.*.options' => 'sometimes|array',
            'variants.*.options.*.attribute_id' => 'nullable|bail|integer',
            'variants.*.options.*.name' => 'nullable|string|max:100',
            'variants.*.options.*.value' => 'required|string',
            'variants.*.sku' => 'required_with:variants|string|max:50|distinct:ignore_case',
            'variants.*.barcode' => 'sometimes|nullable|string|max:100',
            'variants.*.sell_price' => 'sometimes|numeric|min:0',
            'variants.*.buy_price' => 'sometimes|nullable|numeric|min:0',
            'variants.*.sales_tax_id' => 'sometimes|nullable|integer|exists:taxes,id',
            'variants.*.purchase_tax_id' => 'sometimes|nullable|integer|exists:taxes,id',
            'variants.*.min_stock' => 'sometimes|nullable|integer|min:0',
            'variants.*.safe_stock' => 'sometimes|nullable|integer|min:0',
            'variants.*.is_active' => 'sometimes|boolean',
            'variants.*.weight' => 'sometimes|nullable|numeric|min:0',
            'variants.*.unlimited_shop_ids' => 'sometimes|nullable|array',
            'variants.*.unlimited_shop_ids.*' => 'uuid|distinct|exists:channel_shops,id',

            'variants.*.media' => 'sometimes|array',
            'variants.*.media.*.media_uuid' => 'required_without:variants.*.media.*.url|bail|uuid|exists:media,uuid',
            'variants.*.media.*.url' => 'required_without:variants.*.media.*.media_uuid|string',
            'variants.*.media.*.media_type' => 'nullable|in:image,video',
            'variants.*.media.*.is_primary' => 'nullable|boolean',
            'variants.*.media.*.sort_order' => 'nullable|integer',
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {

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

            if ($this->has('category_id') && $v->errors()->has('category_id') === false) {
                $categoryId = $this->input('category_id');
                if ($categoryId !== null) {
                    $repo = app(\Modules\Product\Repositories\CategoryAttributeRepository::class);
                    if (! $repo->isLeaf((int) $categoryId)) {
                        $v->errors()->add(
                            'category_id',
                            'Pilih kategori paling spesifik: Kategori → Sub-Kategori → Jenis Produk wajib dipilih.'
                        );
                    }
                }
            }

            if ($this->has('media')) {
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
            }
        });
    }
}
