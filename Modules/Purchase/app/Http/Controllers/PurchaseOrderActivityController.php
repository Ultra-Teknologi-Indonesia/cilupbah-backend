<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Http\Resources\PurchaseOrderActivityResource;
use Modules\Purchase\Repositories\PurchaseOrderRepository;

class PurchaseOrderActivityController extends Controller
{
    public function __construct(protected PurchaseOrderRepository $repository) {}

    public function index(string $id, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $activities = $this->repository->paginateActivities($id, $perPage);

        return $this->successResponse(
            PurchaseOrderActivityResource::collection($activities->items()),
            null,
            200,
            [
                'next_cursor' => $activities->nextCursor()?->encode(),
                'prev_cursor' => $activities->previousCursor()?->encode(),
                'per_page'    => $perPage,
                'has_more'    => $activities->hasMorePages(),
            ],
        );
    }
}
