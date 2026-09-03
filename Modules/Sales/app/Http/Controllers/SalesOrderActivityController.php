<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Sales\Http\Resources\SalesOrderActivityResource;
use Modules\Sales\Services\SalesOrderService;

class SalesOrderActivityController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesOrderService $service) {}

    public function index(Request $request, string $id)
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(10, min($perPage, 200));

        $paginator = $this->service->getStatusHistory($id, $perPage);

        return $this->successCursorPaginatedResponse(
            SalesOrderActivityResource::collection($paginator),
        );
    }
}
