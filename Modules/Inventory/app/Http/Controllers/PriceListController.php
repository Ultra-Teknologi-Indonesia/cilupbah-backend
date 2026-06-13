<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductWholesalePrice;

class PriceListController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = ProductVariant::select(['id', 'product_id', 'sku', 'buy_price', 'sell_price'])
            ->with(['product:id,name', 'wholesalePrices'])
            ->when($request->search, fn ($q, $v) => $q->where('sku', 'ilike', "%{$v}%"))
            ->when($request->product_id, fn ($q, $v) => $q->where('product_id', $v))
            ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('sku');

        return $this->successPaginatedResponse($query->paginate($request->input('limit', 20)));
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.sell_price' => 'nullable|numeric|min:0',
            'items.*.buy_price' => 'nullable|numeric|min:0',
            'items.*.wholesale_prices' => 'nullable|array',
            'items.*.wholesale_prices.*.customer_type' => 'required_with:items.*.wholesale_prices|string|max:50',
            'items.*.wholesale_prices.*.min_qty' => 'required_with:items.*.wholesale_prices|integer|min:1',
            'items.*.wholesale_prices.*.max_qty' => 'nullable|integer|min:1',
            'items.*.wholesale_prices.*.price' => 'required_with:items.*.wholesale_prices|numeric|min:0',
        ]);

        $updated = 0;

        foreach ($request->input('items') as $item) {
            $variant = ProductVariant::find($item['variant_id']);
            if (! $variant) {
                continue;
            }

            if (isset($item['sell_price'])) {
                $variant->sell_price = $item['sell_price'];
            }
            if (isset($item['buy_price'])) {
                $variant->buy_price = $item['buy_price'];
            }
            $variant->save();

            if (isset($item['wholesale_prices'])) {
                ProductWholesalePrice::where('variant_id', $variant->id)->delete();
                foreach ($item['wholesale_prices'] as $wp) {
                    ProductWholesalePrice::create(array_merge($wp, ['variant_id' => $variant->id]));
                }
            }

            $updated++;
        }

        return $this->successResponse(['updated' => $updated], 'Price list updated.');
    }

    public function pricesByIds(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
        ]);

        $variants = ProductVariant::select(['id', 'product_id', 'sku', 'buy_price', 'sell_price'])
            ->with(['product:id,name', 'wholesalePrices'])
            ->whereIn('id', $request->input('ids'))
            ->get();

        return $this->successResponse($variants);
    }
}
