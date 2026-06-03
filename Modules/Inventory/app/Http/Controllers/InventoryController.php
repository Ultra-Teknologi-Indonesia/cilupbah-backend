<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $stocks = $this->inventoryService->getAllStocks($request->only([
            'item_id', 'location_id'
        ]));

        return response()->json([
            'status' => 'success',
            'data' => $stocks,
        ]);
    }

    public function show(int $itemId): JsonResponse
    {
        $stocks = $this->inventoryService->getStockByItem($itemId);

        return response()->json([
            'status' => 'success',
            'data' => $stocks,
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $history = $this->inventoryService->getMovementHistory($request->only([
            'item_id', 'location_id', 'source', 'date_from', 'date_to'
        ]));

        return response()->json([
            'status' => 'success',
            'data' => $history,
        ]);
    }
}
