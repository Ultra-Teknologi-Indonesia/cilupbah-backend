<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Models\Picklist;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\InventoryTransfer;

class DashboardController extends Controller
{
    use ApiResponse;

    public function taskCounts(Request $request): JsonResponse
    {
        return $this->successResponse([
            'putaway' => Putaway::whereIn('status', ['NOT_STARTED', 'IN_PROGRESS'])->count(),
            'stock_opname' => StockOpname::whereIn('status', ['DRAFT', 'IN_PROGRESS'])->count(),
            'picklist' => Picklist::whereIn('status', ['DRAFT', 'IN_PROGRESS'])->count(),
            'transfer_masuk' => InventoryTransfer::where('status', 'IN_TRANSIT')->count(),
            'transfer_keluar' => InventoryTransfer::whereIn('status', ['DRAFT', 'IN_TRANSIT'])->count(),
        ]);
    }
}
