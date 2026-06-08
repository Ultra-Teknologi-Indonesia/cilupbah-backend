<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\ShipmentService;
use Modules\Outbound\Http\Requests\CreateShipmentRequest;
use Modules\Outbound\Http\Requests\AddShipmentOrdersRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Shipment', description: 'API Endpoints for Shipment management')]
#[OA\Schema(
    schema: 'Shipment',
    title: 'Shipment Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'shipment_no', type: 'string', example: 'SHP-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'courier_name', type: 'string', nullable: true, example: 'JNE'),
        new OA\Property(property: 'courier_code', type: 'string', nullable: true, example: 'jne'),
        new OA\Property(property: 'shipment_type', type: 'string', enum: ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO']),
        new OA\Property(property: 'shipment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED']),
        new OA\Property(property: 'handed_over_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class ShipmentController extends Controller
{
    public function __construct(
        protected ShipmentService $shipmentService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/shipments',
        summary: 'Get paginated shipments',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['SCHEDULED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[courier_name]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[shipment_type]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
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
        $data = $this->shipmentService->getAllPaginated($limit);

        return response()->json(['success' => true, 'data' => $data]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments',
        summary: 'Create a new shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['location_id', 'shipment_type', 'shipment_date'],
                properties: [
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'courier_name', type: 'string', nullable: true),
                    new OA\Property(property: 'courier_code', type: 'string', nullable: true),
                    new OA\Property(property: 'shipment_type', type: 'string', enum: ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO']),
                    new OA\Property(property: 'shipment_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreateShipmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->email;

        $shipment = $this->shipmentService->create($data);

        return response()->json(['success' => true, 'data' => $shipment], 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/{id}',
        summary: 'Get shipment detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
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
        $shipment = $this->shipmentService->getById($id);

        if (!$shipment) {
            return response()->json(['success' => false, 'message' => 'Shipment tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/add-orders',
        summary: 'Add orders to shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function addOrders(string $id, AddShipmentOrdersRequest $request): JsonResponse
    {
        $shipment = $this->shipmentService->addOrders($id, $request->order_ids);

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/remove-orders',
        summary: 'Remove orders from shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function removeOrders(string $id, Request $request): JsonResponse
    {
        $request->validate(['order_ids' => 'required|array|min:1', 'order_ids.*' => 'string']);

        $shipment = $this->shipmentService->removeOrders($id, $request->order_ids);

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/hand-over',
        summary: 'Hand over shipment to courier',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function handOver(string $id): JsonResponse
    {
        $shipment = $this->shipmentService->handOver($id);

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/cancel',
        summary: 'Cancel shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $shipment = $this->shipmentService->cancel($id);

        return response()->json(['success' => true, 'data' => $shipment]);
    }

    #[OA\Delete(
        path: '/api/v1/outbound/shipments/{id}',
        summary: 'Delete scheduled shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->shipmentService->delete($id);

        return response()->json(['success' => true, 'message' => 'Shipment berhasil dihapus.']);
    }
}
