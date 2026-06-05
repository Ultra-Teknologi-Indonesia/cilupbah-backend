<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

use App\Traits\ApiResponse;
use Modules\Order\Services\OrderService;
use Modules\Order\Http\Resources\OrderResource;

#[OA\Tag(name: 'Orders', description: 'API Endpoints for Orders')]
class OrderController extends Controller
{
    use ApiResponse;

    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    #[OA\Get(
        path: '/api/v1/orders',
        summary: 'Get a list of orders',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request)
    {
        if ($request->wantsJson() || $request->is('api/*')) {
            $orders = $this->orderService->getPaginatedOrders();
            $orders->getCollection()->transform(function($order) {
                return new OrderResource($order);
            });
            return $this->successPaginatedResponse($orders);
        }

        return view('order::index');
    }

    public function create()
    {
        return view('order::create');
    }

    #[OA\Post(
        path: '/api/v1/orders',
        summary: 'Create a new order',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreOrderRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Order')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/orders/{order}',
        summary: 'Get order details',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Order')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found')
        ]
    )]
    public function show(Request $request, $id)
    {
        if ($request->wantsJson() || $request->is('api/*')) {
            $order = $this->orderService->getOrderById($id);
            if (!$order) {
                return $this->errorResponse('Data tidak ditemukan', 404);
            }
            return $this->successResponse(new OrderResource($order));
        }

        return view('order::show');
    }

    public function edit($id)
    {
        return view('order::edit');
    }

    #[OA\Put(
        path: '/api/v1/orders/{order}',
        summary: 'Update an existing order',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order to update', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreOrderRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Order')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/orders/{order}',
        summary: 'Delete an order',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found')
        ]
    )]
    public function destroy($id) {}
}
