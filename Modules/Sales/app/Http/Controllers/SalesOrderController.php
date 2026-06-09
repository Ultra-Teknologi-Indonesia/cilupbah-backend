<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

use App\Traits\ApiResponse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Http\Resources\SalesOrderResource;

#[OA\Tag(name: 'Sales Orders', description: 'API Endpoints for Sales Orders')]
#[OA\Schema(
    schema: 'SalesOrder',
    title: 'Sales Order Schema',
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
    schema: 'StoreSalesOrderRequest',
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
class SalesOrderController extends Controller
{
    use ApiResponse;

    protected SalesOrderService $orderService;

    public function __construct(SalesOrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    #[OA\Get(
        path: '/api/v1/sales',
        summary: 'Get a list of sales orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesOrder'))
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
                return new SalesOrderResource($order);
            });
            return $this->successPaginatedResponse($orders);
        }

        return view('sales::index');
    }

    public function create()
    {
        return view('sales::create');
    }

    #[OA\Post(
        path: '/api/v1/sales',
        summary: 'Create a new sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreSalesOrderRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Sales order created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder')
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

        return $this->successResponse(new SalesOrderResource($order), 'Sales order created', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/{id}',
        summary: 'Get sales order details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found')
        ]
    )]
    public function show(Request $request, $id)
    {
        if ($request->wantsJson() || $request->is('api/*')) {
            $order = $this->orderService->getOrderById($id);
            if (!$order) {
                return $this->errorResponse('Data tidak ditemukan', 404);
            }
            return $this->successResponse(new SalesOrderResource($order));
        }

        return view('sales::show');
    }

    public function edit($id)
    {
        return view('sales::edit');
    }

    #[OA\Put(
        path: '/api/v1/sales/{id}',
        summary: 'Update an existing sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order to update', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreSalesOrderRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sales order updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id)
    {
        $order = SalesOrder::findOrFail($id);

        $validated = $request->validate([
            'customer_name'    => 'sometimes|string|max:255',
            'shipping_address' => 'sometimes|string',
            'seller_note'      => 'sometimes|nullable|string',
            'status'           => 'sometimes|string|in:pending,reserved,picked,packed,shipped,cancelled',
            'cancel_reason'    => 'nullable|string|max:255',
        ]);

        $order = $this->orderService->updateOrder($order, $validated);

        return $this->successResponse(new SalesOrderResource($order), 'Sales order updated');
    }

    #[OA\Delete(
        path: '/api/v1/sales/{id}',
        summary: 'Delete a sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order to delete', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales order deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found')
        ]
    )]
    public function destroy($id)
    {
        $order = SalesOrder::with('items')->findOrFail($id);

        $this->orderService->deleteOrder($order);

        return $this->successResponse(null, 'Sales order deleted');
    }
}
