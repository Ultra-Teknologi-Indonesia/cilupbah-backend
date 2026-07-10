<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\PriceListResource;
use Modules\Inventory\Services\PriceListService;

class PriceListController extends Controller
{
    use ApiResponse;

    public function __construct(private PriceListService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $paginator = $this->service->list($request->query('product_id'), $perPage);

        $paginator->getCollection()->transform(
            fn ($variant) => (new PriceListResource($variant))->resolve($request),
        );

        return $this->successPaginatedResponse($paginator);
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

        $updated = $this->service->updatePrices($request->input('items'));

        return $this->successResponse(['updated' => $updated], 'Price list updated.');
    }

    public function pricesByIds(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
        ]);

        $variants = $this->service->pricesByIds($request->input('ids'));

        return $this->successResponse(PriceListResource::collection($variants));
    }
}
