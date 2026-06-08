<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\PicklistService;
use Modules\Outbound\Http\Requests\CreatePicklistRequest;
use Modules\Outbound\Http\Requests\PickItemRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Picklist', description: 'API Endpoints for Picklist management')]
#[OA\Schema(
    schema: 'Picklist',
    title: 'Picklist Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'picklist_no', type: 'string', example: 'PK-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'picker_id', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class PicklistController extends Controller
{
    public function __construct(
        protected PicklistService $picklistService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/picklists',
        summary: 'Get paginated picklists',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[picker_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $data = $this->picklistService->getAllPaginated($limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists',
        summary: 'Create a new picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'picker_id', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreatePicklistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->email;

        $picklist = $this->picklistService->create($data);

        return response()->json(['success' => true, 'data' => $picklist], 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Get picklist detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $picklist = $this->picklistService->getById($id);

        if (!$picklist) {
            return response()->json(['success' => false, 'message' => 'Picklist tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $picklist]);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}/items',
        summary: 'Get picklist items',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function items(string $id, Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $data = $this->picklistService->getItems($id, $limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/assign-picker',
        summary: 'Assign picker to picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['picker_id'],
                properties: [
                    new OA\Property(property: 'picker_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function assignPicker(string $id, Request $request): JsonResponse
    {
        $request->validate(['picker_id' => 'required|string|exists:users,id']);

        $picklist = $this->picklistService->assignPicker($id, $request->picker_id, auth()->user()->email);

        return response()->json(['success' => true, 'data' => $picklist]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/start',
        summary: 'Start picking process',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        $picklist = $this->picklistService->start($id);

        return response()->json(['success' => true, 'data' => $picklist]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/pick',
        summary: 'Record pick for an item',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qty_picked'],
                properties: [
                    new OA\Property(property: 'qty_picked', type: 'integer', minimum: 0),
                    new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function pickItem(string $id, string $itemId, PickItemRequest $request): JsonResponse
    {
        $this->picklistService->pickItem($id, $itemId, $request->validated());

        return response()->json(['success' => true, 'message' => 'Item berhasil di-pick.']);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/complete',
        summary: 'Complete picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        $picklist = $this->picklistService->complete($id);

        return response()->json(['success' => true, 'data' => $picklist]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/cancel',
        summary: 'Cancel picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $picklist = $this->picklistService->cancel($id);

        return response()->json(['success' => true, 'data' => $picklist]);
    }

    #[OA\Delete(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Delete draft picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->picklistService->delete($id);

        return response()->json(['success' => true, 'message' => 'Picklist berhasil dihapus.']);
    }
}
