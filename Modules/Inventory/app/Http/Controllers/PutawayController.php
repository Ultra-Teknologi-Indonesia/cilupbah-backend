<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Http\Requests\AssignPutawayStaffRequest;
use Modules\Inventory\Http\Requests\ProcessPutawayItemRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Putaway', description: 'API Endpoints for Standalone Putaway')]
class PutawayController extends Controller
{
    public function __construct(
        protected PutawayService $putawayService
    ) {}

    #[OA\Get(
        path: '/api/v1/putaway',
        summary: 'Get list of all putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[assigned_to]', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getAllPaginated($limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/not-started',
        summary: 'Get list of not started putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway NOT_STARTED berhasil diambil.'),
        ]
    )]
    public function notStarted(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_NOT_STARTED, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway NOT_STARTED berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/in-progress',
        summary: 'Get list of in-progress putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway IN_PROGRESS berhasil diambil.'),
        ]
    )]
    public function inProgress(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_IN_PROGRESS, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway IN_PROGRESS berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/completed',
        summary: 'Get list of completed putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway COMPLETED berhasil diambil.'),
        ]
    )]
    public function completed(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_COMPLETED, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway COMPLETED berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}',
        summary: 'Get putaway document detail',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail putaway berhasil diambil.'),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Putaway tidak ditemukan.', 404);
        }

        if (!$putaway) {
            return $this->errorResponse('Putaway tidak ditemukan.', 404);
        }

        return $this->successResponse($putaway, 'Detail putaway berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}/items',
        summary: 'Get putaway items',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item putaway berhasil diambil.'),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan.'),
        ]
    )]
    public function items(Request $request, string $id): JsonResponse
    {
        try {
            $limit = $request->query('limit', 10);
            $items = $this->putawayService->getItems($id, $limit);

            return $this->successPaginatedResponse($items, 'Daftar item putaway berhasil diambil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/assign-staff',
        summary: 'Assign staff to putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['data', 'performed_by'],
            properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'putaway_id', type: 'string'),
                        new OA\Property(property: 'assigned_to', type: 'integer'),
                    ]
                )),
                new OA\Property(property: 'performed_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Staff berhasil di-assign ke putaway.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function assignStaff(AssignPutawayStaffRequest $request): JsonResponse
    {
        try {
            $results = $this->putawayService->assignStaff($request->validated());

            return $this->successResponse($results, 'Staff berhasil di-assign ke putaway.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/start',
        summary: 'Start a putaway process',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil dimulai.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->start($id);

            return $this->successResponse($putaway, 'Putaway berhasil dimulai.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/items/{itemId}/process',
        summary: 'Process a putaway item',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['destination_bin_id', 'qty'],
            properties: [
                new OA\Property(property: 'destination_bin_id', type: 'string'),
                new OA\Property(property: 'qty', type: 'integer'),
            ]
        )),
        responses: [
            new OA\Response(response: 202, description: 'Proses putaway item sedang dijalankan.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function processItem(ProcessPutawayItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $this->putawayService->processItem($id, $itemId, $request->validated());

            return $this->successResponse(null, 'Proses putaway item sedang dijalankan.', 202);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/complete',
        summary: 'Complete a putaway process',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil diselesaikan.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->complete($id);

            return $this->successResponse($putaway, 'Putaway berhasil diselesaikan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
