<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Http\Resources\PurchaseOrderActivityResource;
use Modules\Purchase\Models\PurchaseOrderActivity;

class PurchaseOrderActivityController extends Controller
{
    public function index(string $id, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $activities = PurchaseOrderActivity::where('purchase_order_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => PurchaseOrderActivityResource::collection($activities->items()),
            'meta' => [
                'next_cursor' => $activities->nextCursor()?->encode(),
                'prev_cursor' => $activities->previousCursor()?->encode(),
                'per_page'    => $perPage,
                'has_more'    => $activities->hasMorePages(),
            ],
        ]);
    }
}
