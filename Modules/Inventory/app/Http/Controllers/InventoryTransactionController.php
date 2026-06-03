<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Requests\PutawayStockRequest;

class InventoryTransactionController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->adjust($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment berhasil.',
                'data' => $inventory,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function transfer(TransferStockRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->transfer($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Stock transfer berhasil.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function putaway(PutawayStockRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->putaway($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Putaway berhasil.',
                'data' => $inventory,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
