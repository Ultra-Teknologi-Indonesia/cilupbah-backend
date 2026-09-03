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

        return $this->successCursorPaginatedResponse(
            PurchaseOrderActivityResource::collection($activities),
        );
    }
}
