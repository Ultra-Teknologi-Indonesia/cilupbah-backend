<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Http\Resources\PurchaseOrderActivityResource;
use Modules\Purchase\Services\PurchaseOrderService;

class PurchaseOrderActivityController extends Controller
{
    public function __construct(protected PurchaseOrderService $service) {}

    public function index(string $id, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

        $activities = $this->service->getActivities($id, $perPage);

        return $this->successCursorPaginatedResponse(
            PurchaseOrderActivityResource::collection($activities),
        );
    }
}
