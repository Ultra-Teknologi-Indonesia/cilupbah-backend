<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Sales\Http\Resources\SalesOrderActivityResource;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;

class SalesOrderActivityController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $id)
    {
        $order = SalesOrder::findOrFail($id);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(10, min($perPage, 200));

        $paginator = SalesOrderStatusHistory::query()
            ->with('order:id,salesorder_no')
            ->where('salesorder_id', $order->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => SalesOrderActivityResource::collection($paginator->items()),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'per_page'    => $perPage,
                'has_more'    => $paginator->hasMorePages(),
            ],
        ]);
    }
}
