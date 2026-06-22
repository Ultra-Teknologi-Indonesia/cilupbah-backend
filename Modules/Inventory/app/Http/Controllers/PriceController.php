<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\PriceService;

class PriceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PriceService $priceService,
    ) {}

    public function online(Request $request): JsonResponse
    {
        $result = $this->priceService->getOnlinePrices($request->all());

        return response()->json([
            'status' => 'success',
            'channels' => $result['channels'],
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function offline(Request $request): JsonResponse
    {
        $result = $this->priceService->getOfflinePrices($request->all());

        return response()->json([
            'status' => 'success',
            'locations' => $result['locations'],
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function updateOnline(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'items.*.channel_shop_id' => 'required|uuid|exists:channel_shops,id',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $updated = $this->priceService->updateOnlinePrices($request->input('items'));

        return $this->successResponse(['updated' => $updated], 'Harga online berhasil diperbarui.');
    }

    public function updateOffline(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'items.*.location_id' => 'required|uuid|exists:locations,id',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.is_active' => 'sometimes|boolean',
        ]);

        $updated = $this->priceService->updateOfflinePrices($request->input('items'));

        return $this->successResponse(['updated' => $updated], 'Harga offline berhasil diperbarui.');
    }
}
