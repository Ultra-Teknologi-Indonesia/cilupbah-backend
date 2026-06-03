<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $stocks = $this->inventoryService->getAllPaginated($limit);

        return $this->successPaginatedResponse($stocks, 'Daftar stok berhasil diambil');
    }

    public function show(int $itemId): JsonResponse
    {
        $stocks = $this->inventoryService->getStockByItem($itemId);

        return $this->successResponse($stocks, 'Detail stok per item berhasil diambil');
    }

    public function movements(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $movements = $this->inventoryService->getHistoryPaginated($limit);

        return $this->successPaginatedResponse($movements, 'Riwayat pergerakan stok berhasil diambil');
    }
}
