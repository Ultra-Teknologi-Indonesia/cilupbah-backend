<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

use App\Traits\ApiResponse;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Order\Http\Resources\OrderResource;

#[OA\Tag(name: 'Orders', description: 'API Endpoints for Orders')]
#[OA\Schema(
    schema: 'Order',
    title: 'Order Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-2026-0001'),
        new OA\Property(property: 'channel_shop_id', type: 'string', nullable: true),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'sub_total', type: 'number', format: 'double', example: 100000),
        new OA\Property(property: 'total_disc', type: 'number', format: 'double', example: 0),
        new OA\Property(property: 'total_tax', type: 'number', format: 'double', example: 11000),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'double', example: 15000),
        new OA\Property(property: 'insurance_cost', type: 'number', format: 'double', example: 0),
        new OA\Property(property: 'grand_total', type: 'number', format: 'double', example: 126000),
        new OA\Property(property: 'status', type: 'string', example: 'PENDING'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
        new OA\Property(property: 'source', type: 'string', example: 'manual', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StoreOrderRequest',
    required: ['salesorder_no', 'customer_name', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-2026-0001'),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'channel_shop_id', type: 'string', nullable: true),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'sub_total', type: 'number', nullable: true),
        new OA\Property(property: 'grand_total', type: 'number', nullable: true),
        new OA\Property(property: 'source', type: 'string', nullable: true),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['sku', 'qty_in_base', 'price'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string', example: 'LAPTOP-001'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'qty_in_base', type: 'integer', example: 2),
                    new OA\Property(property: 'price', type: 'number', example: 7500000),
                    new OA\Property(property: 'disc', type: 'number', nullable: true),
                    new OA\Property(property: 'amount', type: 'number', nullable: true),
                ]
            )
        )
    ]
)]
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salesorder_no'       => 'required|string',
            'channel_shop_id'     => 'nullable|string',
            'customer_name'       => 'required|string|max:255',
            'transaction_date'    => 'nullable|date',
            'sub_total'           => 'nullable|numeric|min:0',
            'total_disc'          => 'nullable|numeric|min:0',
            'total_tax'           => 'nullable|numeric|min:0',
            'shipping_cost'       => 'nullable|numeric|min:0',
            'insurance_cost'      => 'nullable|numeric|min:0',
            'grand_total'         => 'nullable|numeric|min:0',
            'shipping_full_name'  => 'nullable|string|max:255',
            'shipping_phone'      => 'nullable|string|max:50',
            'shipping_address'    => 'nullable|string',
            'shipping_area'       => 'nullable|string|max:255',
            'shipping_city'       => 'nullable|string|max:255',
            'shipping_province'   => 'nullable|string|max:255',
            'shipping_post_code'  => 'nullable|string|max:20',
            'shipping_country'    => 'nullable|string|max:100',
            'payment_method'      => 'nullable|string|max:100',
            'payment_method_name' => 'nullable|string|max:255',
            'source'              => 'nullable|string|max:50',
            'buyer_message'       => 'nullable|string',
            'seller_note'         => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.sku'         => 'required|string',
            'items.*.description' => 'nullable|string',
            'items.*.qty_in_base' => 'required|integer|min:1',
            'items.*.price'       => 'required|numeric|min:0',
            'items.*.disc'        => 'nullable|numeric|min:0',
            'items.*.disc_amount' => 'nullable|numeric|min:0',
            'items.*.tax_amount'  => 'nullable|numeric|min:0',
            'items.*.amount'      => 'nullable|numeric|min:0',
        ]);

        $order = $this->orderService->createOrder($validated);

        return $this->successResponse(new OrderResource($order), 'Order created', 201);
    }

    #[OA\Get(
        path: '/api/v1/orders/{order}',
        summary: 'Get order details',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order', schema: new OA\Schema(type: 'string'))
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
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order to update', schema: new OA\Schema(type: 'string'))
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
    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'customer_name'    => 'sometimes|string|max:255',
            'shipping_address' => 'sometimes|string',
            'seller_note'      => 'sometimes|nullable|string',
            'status'           => 'sometimes|string|in:pending,reserved,picked,packed,shipped,cancelled',
            'cancel_reason'    => 'nullable|string|max:255',
        ]);

        $order = $this->orderService->updateOrder($order, $validated);

        return $this->successResponse(new OrderResource($order), 'Order updated');
    }

    #[OA\Delete(
        path: '/api/v1/orders/{order}',
        summary: 'Delete an order',
        security: [['bearerAuth' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'ID of the order to delete', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found')
        ]
    )]
    public function destroy($id)
    {
        $order = Order::with('items')->findOrFail($id);

        $this->orderService->deleteOrder($order);

        return $this->successResponse(null, 'Order deleted');
    }
}
