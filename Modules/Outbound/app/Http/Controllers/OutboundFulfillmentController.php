<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\OutboundFulfillmentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Fulfillment', description: 'API Endpoints for Outbound Fulfillment Queue Views')]
class OutboundFulfillmentController extends Controller
{
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
            'order_id' => 'required|string|exists:orders,id',
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
    public function requestCancelOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string|exists:orders,id',
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
}
