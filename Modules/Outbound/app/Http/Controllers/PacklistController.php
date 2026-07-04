<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\PacklistService;
use Modules\Outbound\Http\Requests\CreatePacklistRequest;
use Modules\Outbound\Http\Requests\PackItemRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Packlist', description: 'API Endpoints for Packlist management')]
#[OA\Schema(
    schema: 'Packlist',
    title: 'Packlist Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'packlist_no', type: 'string', example: 'PCK-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'packer_id', type: 'string', nullable: true),
        new OA\Property(property: 'order_id', type: 'string'),
        new OA\Property(property: 'picklist_id', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'package_count', type: 'integer', example: 1),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class PacklistController extends Controller
{
    public function __construct(
        protected PacklistService $packlistService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/packlists',
        summary: 'Get paginated packlists',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[order_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/outbound/packlists/scan-order',
        summary: 'Scan order barcode/no to get packlist items for packing',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'order_no', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function scanOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_no' => 'required|string',
            'packer_id' => 'nullable|string|exists:users,id',
        ]);

        $packlist = $this->packlistService->scanOrder(
            $request->query('order_no'),
            $request->query('packer_id'),
            auth()->user()?->email,
        );

        if (!$packlist) {
            return $this->errorResponse('Pesanan tidak ditemukan atau belum siap packing.', 404);
        }

        return $this->successResponse($packlist);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $data = $this->packlistService->getAllPaginated($limit);

        return $this->successResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists',
        summary: 'Create a new packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'packer_id', type: 'string', nullable: true),
                    new OA\Property(property: 'picklist_id', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreatePacklistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->email;

        $packlist = $this->packlistService->create($data);

        return $this->successResponse($packlist, null, 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/packlists/{id}',
        summary: 'Get packlist detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
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
        $packlist = $this->packlistService->getById($id);

        if (!$packlist) {
            return $this->errorResponse('Packlist tidak ditemukan.', 404);
        }

        return $this->successResponse($packlist);
    }

    #[OA\Get(
        path: '/api/v1/outbound/packlists/{id}/items',
        summary: 'Get packlist items',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
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
        $data = $this->packlistService->getItems($id, $limit);

        return $this->successResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/assign-packer',
        summary: 'Assign packer to packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['packer_id'],
                properties: [
                    new OA\Property(property: 'packer_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function assignPacker(string $id, Request $request): JsonResponse
    {
        $request->validate(['packer_id' => 'required|string|exists:users,id']);

        $packlist = $this->packlistService->assignPacker($id, $request->packer_id, auth()->user()->email);

        return $this->successResponse($packlist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/start',
        summary: 'Start packing process',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        $packlist = $this->packlistService->start($id);

        return $this->successResponse($packlist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/items/{itemId}/pack',
        summary: 'Record pack for an item',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qty_packed'],
                properties: [
                    new OA\Property(property: 'qty_packed', type: 'integer', minimum: 0),
                    new OA\Property(property: 'barcode_verified', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function packItem(string $id, string $itemId, PackItemRequest $request): JsonResponse
    {
        $this->packlistService->packItem($id, $itemId, $request->validated());

        return $this->successResponse(null, 'Item berhasil di-pack.');
    }

    public function unpackItem(string $id, string $itemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

        try {
            $this->packlistService->unpackItem($id, $itemId, $validated['qty'] ?? null);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Pack berhasil dikoreksi.');
    }

    public function unpackItems(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        try {
            $this->packlistService->unpackItems($id, $validated['items']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Pack berhasil dikoreksi.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/verify-barcode',
        summary: 'Verify barcode/SKU in packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['barcode'],
                properties: [
                    new OA\Property(property: 'barcode', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function verifyBarcode(string $id, Request $request): JsonResponse
    {
        $request->validate(['barcode' => 'required|string']);

        $result = $this->packlistService->verifyBarcode($id, $request->barcode);

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/complete',
        summary: 'Complete packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        $packlist = $this->packlistService->complete($id);

        return $this->successResponse($packlist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/packlists/{id}/cancel',
        summary: 'Cancel packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $packlist = $this->packlistService->cancel($id);

        return $this->successResponse($packlist);
    }

    #[OA\Delete(
        path: '/api/v1/outbound/packlists/{id}',
        summary: 'Delete draft packlist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Packlist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->packlistService->delete($id);

        return $this->successResponse(null, 'Packlist berhasil dihapus.');
    }
}
