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
            'brand_id' => 'sometimes|nullable|bail|integer|exists:brands,id',
            'search_keyword' => 'sometimes|nullable|string',
            'order_type' => 'sometimes|in:REGULER,PREORDER,COD',
            'indent_days' => 'sometimes|nullable|integer|min:0|required_if:order_type,PREORDER',
            'condition' => 'sometimes|in:NEW,USED',
            'status' => ['sometimes', Rule::in(['download', 'master'])],
            'is_bundle' => 'sometimes|boolean',
            'is_consignment' => 'sometimes|boolean',
            'weight' => 'sometimes|nullable|numeric|min:0',
            'length' => 'sometimes|nullable|numeric|min:0',
            'width' => 'sometimes|nullable|numeric|min:0',
            'height' => 'sometimes|nullable|numeric|min:0',
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

            'media' => 'sometimes|array',
            'media.*.media_uuid' => 'required_without:media.*.url|bail|uuid|exists:media,uuid',
            'media.*.url' => 'required_without:media.*.media_uuid|string',
            'media.*.media_type' => 'nullable|in:image,video',
            'media.*.is_primary' => 'nullable|boolean',
            'media.*.sort_order' => 'nullable|integer',

            'variation_types' => 'sometimes|nullable|array|max:2',
            'variation_types.*.attribute_id' => 'required|bail|integer|distinct|exists:attributes,id',
            'variation_types.*.sort_order' => 'nullable|integer|min:0',

            'specifications' => 'sometimes|nullable|array',
            'specifications.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
            'specifications.*.attribute_option_id' => 'nullable|bail|integer|exists:attribute_options,id',
            'specifications.*.text_value' => 'nullable|string',

            'variants' => 'sometimes|array|min:1',

            'variants.*.options' => 'sometimes|array',
            'variants.*.options.*.attribute_id' => 'required|bail|integer|exists:attributes,id',
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
            'variants.*.unlimited_shop_ids' => 'sometimes|nullable|array',
            'variants.*.unlimited_shop_ids.*' => 'uuid|distinct|exists:channel_shops,id',
            'variants.*.channel_prices' => 'sometimes|nullable|array',
            'variants.*.channel_prices.*.channel_shop_id' => 'required_with:variants.*.channel_prices|uuid',
            'variants.*.channel_prices.*.price' => 'required_with:variants.*.channel_prices|numeric|min:0',

            'variants.*.media' => 'sometimes|array',
            'variants.*.media.*.media_uuid' => 'required_without:variants.*.media.*.url|bail|uuid|exists:media,uuid',
            'variants.*.media.*.url' => 'required_without:variants.*.media.*.media_uuid|string',
            'variants.*.media.*.media_type' => 'nullable|in:image,video',
            'variants.*.media.*.is_primary' => 'nullable|boolean',
            'variants.*.media.*.sort_order' => 'nullable|integer',
        ];
    }
}
