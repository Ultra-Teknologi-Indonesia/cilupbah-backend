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

            return $this->successResponse($inventory, 'Stock adjustment berhasil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function transferOut(\Modules\Inventory\Http\Requests\TransferOutRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->transferOut($request->validated());
            return $this->successResponse($result, 'Transfer Out berhasil dibuat, barang sedang dalam perjalanan (Transit).');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function transferIn(\Modules\Inventory\Http\Requests\TransferInRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->transferIn($id, $request->validated());
            return $this->successResponse($result, 'Transfer In berhasil, stok telah masuk ke gudang tujuan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function transitList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $repo = app(\Modules\Inventory\Repositories\InventoryTransferRepository::class);
        // Default filter for transit is handled by status query param, but we could enforce it here if needed.
        $transfers = $repo->getTransfersPaginated(['status' => 'IN_TRANSIT'], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar barang dalam perjalanan (Transit).');
    }

    public function transfersList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $repo = app(\Modules\Inventory\Repositories\InventoryTransferRepository::class);
        $transfers = $repo->getTransfersPaginated([], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar semua dokumen transfer.');
    }

    public function putaway(PutawayStockRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->putaway($request->validated());

            return $this->successResponse($inventory, 'Putaway berhasil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
