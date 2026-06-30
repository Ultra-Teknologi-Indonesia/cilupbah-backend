<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\OutboundFulfillmentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Fulfillment', description: 'API Endpoints for Outbound Fulfillment Queue Views')]
class OutboundFulfillmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected OutboundFulfillmentService $fulfillmentService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/orders/{stage}',
        summary: 'Get orders by fulfillment stage',
        description: 'Available stages: ready-to-process, ready-to-pick, on-picking, finish-pick, failed-pick, on-packing, finish-pack, ready-to-ship, shipped, empty-stock, request-cancel',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        parameters: [
            new OA\Parameter(name: 'stage', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['ready-to-process', 'ready-to-pick', 'on-picking', 'finish-pick', 'failed-pick', 'on-packing', 'finish-pack', 'ready-to-ship', 'shipped', 'empty-stock', 'request-cancel'])),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, description: 'Search by salesorder_no', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[source]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Invalid stage'),
        ]
    )]
    public function ordersByStage(string $stage, Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        try {
            $data = $this->fulfillmentService->getOrdersByStage($stage, $limit);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/change-location',
        summary: 'Change order fulfillment location',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'location_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Bad Request'),
        ]
    )]
    public function changeLocation(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
            'location_id' => 'required|string|exists:locations,id',
        ]);

        try {
            $order = $this->fulfillmentService->changeLocation(
                $request->order_id,
                $request->location_id,
                auth()->user()->email,
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/request-cancel',
        summary: 'Request order cancellation',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Bad Request'),
        ]
    )]
    #[OA\Post(
        path: '/api/v1/outbound/orders/get-by-no',
        summary: 'Get sales order by salesorder_no for picking',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_no'],
                properties: [
                    new OA\Property(property: 'order_no', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function getOrderByNo(Request $request): JsonResponse
    {
        $request->validate(['order_no' => 'required|string']);

        $order = $this->fulfillmentService->findOrderByNo($request->order_no);

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order tidak ditemukan.'], 404);
        }

        try {
            $userEmail = auth()->user() ? auth()->user()->email : 'system';
            $this->fulfillmentService->moveToReadyToPick(
                $order->id,
                $order->location_id,
                $userEmail
            );

            $order = $this->fulfillmentService->findOrderByNo($request->order_no);
        } catch (\Exception $e) {

        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/move-to-ready-to-pick',
        summary: 'Move order to ready-to-pick stage (creates DRAFT picklist)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'location_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Bad Request'),
        ]
    )]
    public function moveToReadyToPick(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
            'location_id' => 'required|string|exists:locations,id',
        ]);

        try {
            $order = $this->fulfillmentService->moveToReadyToPick(
                $request->order_id,
                $request->location_id,
                auth()->user()->email,
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $order, 'message' => 'Order dipindah ke ready-to-pick.']);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/move-to-ready-to-process',
        summary: 'Move order back to ready-to-process stage (removes DRAFT picklist items)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Bad Request'),
        ]
    )]
    public function moveToReadyToProcess(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
        ]);

        try {
            $order = $this->fulfillmentService->moveToReadyToProcess($request->order_id);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $order, 'message' => 'Order dipindah ke ready-to-process.']);
    }

    public function requestCancelOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->fulfillmentService->requestCancelOrder(
                $request->order_id,
                $request->reason,
                auth()->user()->email,
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/ready-to-ship',
        summary: 'Mark orders as Ready To Ship (Siap Dikirim) across channels',
        description: 'Omnichannel dispatcher. For each order, ships/packs via its marketplace (shopee/tiktok/lazada) or marks ready locally for manual orders. Per-order results are returned; one failure never aborts the batch.',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
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
            new OA\Response(response: 200, description: 'Success (per-order results)'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function readyToShip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'required|string|exists:sales_orders,id',
        ]);

        $results = $this->fulfillmentService->readyToShip($validated['order_ids']);

        return $this->successResponse($results, 'Proses Siap Dikirim selesai.');
    }

    #[OA\Get(
        path: '/api/v1/outbound/pickers',
        summary: 'List warehouse users eligible as pickers',
        description: 'Returns active users. When location_id is provided, filters to users assigned to that warehouse/location.',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Fulfillment'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function pickers(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'nullable|string',
        ]);

        $query = User::query();

        if ($request->filled('location_id')) {
            $query->where('warehouse_id', $request->query('location_id'));
        }

        $pickers = $query
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
            ])
            ->values();

        return $this->successResponse($pickers, 'Daftar picker.');
    }
}
