<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Orders', description: 'API Endpoints for Orders')]
#[OA\Schema(
    schema: 'Order',
    title: 'Order Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-12345'),
        new OA\Property(property: 'channel_shop_id', type: 'integer', example: 1),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'sub_total', type: 'number', format: 'float', example: 100.00),
        new OA\Property(property: 'total_disc', type: 'number', format: 'float', example: 10.00),
        new OA\Property(property: 'total_tax', type: 'number', format: 'float', example: 5.00),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'float', example: 15.00),
        new OA\Property(property: 'insurance_cost', type: 'number', format: 'float', example: 2.00),
        new OA\Property(property: 'grand_total', type: 'number', format: 'float', example: 112.00),
        new OA\Property(property: 'shipping_full_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'shipping_phone', type: 'string', example: '+6281234567890'),
        new OA\Property(property: 'shipping_address', type: 'string', example: 'Jl. Sudirman No. 1'),
        new OA\Property(property: 'shipping_area', type: 'string', example: 'Kebayoran Baru'),
        new OA\Property(property: 'shipping_city', type: 'string', example: 'Jakarta Selatan'),
        new OA\Property(property: 'shipping_province', type: 'string', example: 'DKI Jakarta'),
        new OA\Property(property: 'shipping_post_code', type: 'string', example: '12190'),
        new OA\Property(property: 'shipping_country', type: 'string', example: 'Indonesia'),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
        new OA\Property(property: 'cancel_reason', type: 'string', example: null),
        new OA\Property(property: 'channel_status', type: 'string', example: 'new'),
        new OA\Property(property: 'payment_method', type: 'string', example: 'bank_transfer'),
        new OA\Property(property: 'source', type: 'string', example: 'api'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreOrderRequest',
    required: ['salesorder_no', 'customer_name', 'transaction_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-12345'),
        new OA\Property(property: 'channel_shop_id', type: 'integer', example: 1),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'sub_total', type: 'number', format: 'float', example: 100.00),
        new OA\Property(property: 'total_disc', type: 'number', format: 'float', example: 10.00),
        new OA\Property(property: 'total_tax', type: 'number', format: 'float', example: 5.00),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'float', example: 15.00),
        new OA\Property(property: 'insurance_cost', type: 'number', format: 'float', example: 2.00),
        new OA\Property(property: 'grand_total', type: 'number', format: 'float', example: 112.00),
        new OA\Property(property: 'shipping_full_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'shipping_phone', type: 'string', example: '+6281234567890'),
        new OA\Property(property: 'shipping_address', type: 'string', example: 'Jl. Sudirman No. 1'),
        new OA\Property(property: 'shipping_area', type: 'string', example: 'Kebayoran Baru'),
        new OA\Property(property: 'shipping_city', type: 'string', example: 'Jakarta Selatan'),
        new OA\Property(property: 'shipping_province', type: 'string', example: 'DKI Jakarta'),
        new OA\Property(property: 'shipping_post_code', type: 'string', example: '12190'),
        new OA\Property(property: 'shipping_country', type: 'string', example: 'Indonesia'),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
        new OA\Property(property: 'cancel_reason', type: 'string', example: null),
        new OA\Property(property: 'channel_status', type: 'string', example: 'new'),
        new OA\Property(property: 'payment_method', type: 'string', example: 'bank_transfer'),
        new OA\Property(property: 'source', type: 'string', example: 'api'),
    ]
)]
class OrderController extends Controller
{
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
            $orders = \Modules\Order\Models\Order::latest()->paginate(15);
            return response()->json([
                'status' => 'success',
                'data' => $orders
            ]);
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
            $order = \Modules\Order\Models\Order::with('items')->find($id);
            if (!$order) {
                return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan', 'data' => null], 404);
            }
            return response()->json([
                'status' => 'success',
                'data' => $order
            ]);
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
