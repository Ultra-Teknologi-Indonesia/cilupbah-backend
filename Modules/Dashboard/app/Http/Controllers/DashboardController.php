<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Dashboard\Services\DashboardService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard', description: 'API Endpoints for the main dashboard (Beranda)')]
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    #[OA\Get(
        path: '/api/v1/dashboard/summary',
        summary: 'Dashboard KPI summary',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard KPI summary'),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $data = $this->dashboardService->summary($request->only(['date_from', 'date_to', 'location_id']));

        return $this->successResponse($data, 'Ringkasan dashboard berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/dashboard/queues/{queue}',
        summary: 'Dashboard action queue (paginated)',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'queue', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['ready-to-process', 'empty-stock', 'failed-pick', 'pending-cancel'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated action queue'),
        ]
    )]
    public function queue(Request $request, string $queue): JsonResponse
    {
        if (! DashboardService::isValidQueue($queue)) {
            return $this->errorResponse("Queue '{$queue}' tidak dikenal.", 404);
        }

        $perPage = (int) $request->integer('per_page', 5);
        $perPage = max(1, min($perPage, 50));

        $paginator = $this->dashboardService->queue($queue, $perPage);

        return $this->successPaginatedResponse($paginator, 'Antrian dashboard berhasil diambil.');
    }
}
