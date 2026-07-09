<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

use App\Traits\ApiResponse;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Sales\Exports\CancelledOrdersExport;
use Modules\Sales\Exports\SalesOrdersExport;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Http\Requests\SaveCourierPickupRequest;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Jobs\CallShopeeDriverJob;
use Modules\Sales\Support\ShopeeInstantEligibility;
use Modules\Channel\Services\ShopeeOrderService;

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

    public function __construct(SalesOrderService $orderService, protected ImpexActivityService $activityService)
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
            $orders->getCollection()->transform(function ($order) {
                return new SalesOrderResource($order);
            });
            return $this->successPaginatedResponse($orders);
        }

        return view('sales::index');
    }

    public function counts()
    {
        return $this->successResponse($this->orderService->getTabCounts());
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
            'salesorder_no'       => 'nullable|string',
            'channel_order_no'    => 'nullable|string',
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

    #[OA\Get(
        path: '/api/v1/sales/orders/cancel',
        summary: 'Get cancelled orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function cancelled(Request $request)
    {
        $limit = $request->query('limit', 10);
        $orders = $this->orderService->getCancelledOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order cancelled');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/cancelled/export',
        summary: 'Download cancelled orders as XLSX (recap for outbound reconciliation)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'post_pack_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'XLSX file stream', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
        ]
    )]
    public function export(Request $request)
    {
        $validated = $request->validate([
            'tab'         => 'nullable|string|max:30',
            'date_from'   => 'nullable|date',
            'date_to'     => 'nullable|date|after_or_equal:date_from',
            'source'      => 'nullable|string|max:50',
            'search'      => 'nullable|string|max:200',
            'store_id'    => 'nullable|uuid',
            'location_id' => 'nullable|uuid',
        ]);

        $export = new SalesOrdersExport(
            tab:        $validated['tab'] ?? null,
            dateFrom:   $validated['date_from'] ?? null,
            dateTo:     $validated['date_to'] ?? null,
            source:     $validated['source'] ?? null,
            search:     $validated['search'] ?? null,
            storeId:    $validated['store_id'] ?? null,
            locationId: $validated['location_id'] ?? null,
        );

        $filename = sprintf(
            'pesanan-%s-%s.xlsx',
            $validated['tab'] ?? 'semua',
            now()->format('Ymd-His'),
        );

        $this->activityService->recordCompleted(
            ImpexActivity::DIRECTION_EXPORT,
            'Export Pesanan',
            $request->user()?->id,
        );

        return Excel::download($export, $filename);
    }

    public function exportCancelled(Request $request)
    {
        $validated = $request->validate([
            'date_from'      => 'nullable|date',
            'date_to'        => 'nullable|date|after_or_equal:date_from',
            'post_pack_only' => 'nullable|boolean',
            'source'         => 'nullable|string|max:50',
        ]);

        $export = new CancelledOrdersExport(
            dateFrom:     $validated['date_from'] ?? null,
            dateTo:       $validated['date_to'] ?? null,
            postPackOnly: (bool) ($validated['post_pack_only'] ?? false),
            source:       $validated['source'] ?? null,
        );

        $filename = sprintf(
            'cancel-orders-%s-%s.xlsx',
            $validated['date_from'] ?? 'all',
            $validated['date_to'] ?? now()->format('Y-m-d'),
        );

        $this->activityService->recordCompleted(
            ImpexActivity::DIRECTION_EXPORT,
            'Export Pesanan Dibatalkan',
            $request->user()?->id,
        );

        return Excel::download($export, $filename);
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/completed',
        summary: 'Get completed (shipped) orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function completed(Request $request)
    {
        $limit = $request->query('limit', 10);
        $orders = $this->orderService->getCompletedOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order completed');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/failed',
        summary: 'Get failed orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function failed(Request $request)
    {
        $limit = $request->query('limit', 10);
        $orders = $this->orderService->getFailedOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order failed');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/returned-list',
        summary: 'Get orders with returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function returnedList(Request $request)
    {
        $limit = $request->query('limit', 10);
        $orders = $this->orderService->getReturnedOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order yang di-return');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/delete-canceled',
        summary: 'Bulk delete cancelled orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Orders deleted')]
    )]
    public function deleteCanceled(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|bail|uuid|exists:sales_orders,id',
        ]);

        $count = $this->orderService->bulkDeleteCancelled($validated['ids']);

        return $this->successResponse(['deleted' => $count], "{$count} order cancelled berhasil dihapus");
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/move-to-ready',
        summary: 'Move orders back to ready-to-process (from empty-stock or failed-pick)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Orders moved to ready-to-process')]
    )]
    public function moveToReadyToProcess(Request $request)
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|bail|uuid|exists:sales_orders,id',
        ]);

        $result = $this->orderService->moveToReadyToProcess($validated['order_ids']);
        $moved = $result['moved'];
        $skipped = $result['skipped'];

        $message = "{$moved} order berhasil dipindahkan ke siap proses";
        if (! empty($skipped)) {
            $skippedCount = count($skipped);
            $message .= " · {$skippedCount} order dilewati karena stok kosong (cek tab Stok Kosong)";
        }

        return $this->successResponse([
            'moved'   => $moved,
            'skipped' => $skipped,
        ], $message);
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/mark-as-complete',
        summary: 'Mark orders as complete (shipped)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Orders marked as complete')]
    )]
    public function markAsComplete(Request $request)
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|bail|uuid|exists:sales_orders,id',
        ]);

        $count = $this->orderService->markAsComplete($validated['order_ids']);

        return $this->successResponse(['completed' => $count], "{$count} order berhasil di-complete");
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/save-airwaybill',
        summary: 'Save AWB (tracking number) to order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id', 'tracking_number'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'tracking_number', type: 'string'),
                new OA\Property(property: 'shipping_provider', type: 'string', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'AWB berhasil disimpan')]
    )]
    public function saveAirwaybill(Request $request)
    {
        $validated = $request->validate([
            'order_id'         => 'required|bail|uuid|exists:sales_orders,id',
            'tracking_number'  => 'required|string|max:255',
            'shipping_provider' => 'nullable|string|max:255',
        ]);

        $order = $this->orderService->saveAirwaybill($validated);

        return $this->successResponse(new SalesOrderResource($order), 'AWB berhasil disimpan');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/save-received-date',
        summary: 'Save received date on order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'received_date', type: 'string', format: 'date-time', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Received date berhasil disimpan')]
    )]
    public function saveReceivedDate(Request $request)
    {
        $validated = $request->validate([
            'order_id'      => 'required|bail|uuid|exists:sales_orders,id',
            'received_date' => 'nullable|date',
        ]);

        $order = $this->orderService->saveReceivedDate($validated);

        return $this->successResponse(new SalesOrderResource($order), 'Received date berhasil disimpan');
    }

    #[OA\Put(
        path: '/api/v1/sales/{id}/courier-pickup',
        summary: 'Save courier pickup proof (name, phone, pickup code)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'courier_name', type: 'string', nullable: true),
                new OA\Property(property: 'courier_phone', type: 'string', nullable: true),
                new OA\Property(property: 'pickup_code', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Bukti pickup kurir berhasil disimpan'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function saveCourierPickup(SaveCourierPickupRequest $request, string $id)
    {
        $order = $this->orderService->saveCourierPickup(
            $id,
            $request->only(['courier_name', 'courier_phone', 'pickup_code'])
        );

        return $this->successResponse(new SalesOrderResource($order), 'Bukti pickup kurir berhasil disimpan');
    }

    #[OA\Post(
        path: '/api/v1/sales/{id}/courier-pickup/photo',
        summary: 'Upload courier ID photo (pickup proof)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto identitas kurir berhasil diunggah'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function uploadCourierIdPhoto(Request $request, string $id)
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:4096'],
        ]);

        $order = $this->orderService->replaceCourierIdPhoto($id, $request->file('photo'));

        return $this->successResponse(new SalesOrderResource($order), 'Foto identitas kurir berhasil diunggah');
    }

    #[OA\Delete(
        path: '/api/v1/sales/{id}/courier-pickup/photo',
        summary: 'Delete courier ID photo (pickup proof)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto identitas kurir berhasil dihapus'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function deleteCourierIdPhoto(string $id)
    {
        $order = $this->orderService->deleteCourierIdPhoto($id);

        return $this->successResponse(new SalesOrderResource($order), 'Foto identitas kurir berhasil dihapus');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/set-as-paid',
        summary: 'Mark order as paid',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'payment_method', type: 'string', nullable: true),
                new OA\Property(property: 'paid_time', type: 'string', format: 'date-time', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Order berhasil diset paid')]
    )]
    public function setAsPaid(Request $request)
    {
        $validated = $request->validate([
            'order_id'       => 'required|bail|uuid|exists:sales_orders,id',
            'payment_method' => 'nullable|string|max:100',
            'paid_time'      => 'nullable|date',
        ]);

        $order = $this->orderService->setAsPaid($validated);

        return $this->successResponse(new SalesOrderResource($order), 'Order berhasil diset paid');
    }

    #[OA\Post(
        path: '/api/v1/sales/request-awb-order',
        summary: 'Request AWB from courier service',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'courier_code', type: 'string', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'AWB request submitted')]
    )]
    public function requestAwb(Request $request)
    {
        $validated = $request->validate([
            'order_id'     => 'required|bail|uuid|exists:sales_orders,id',
            'courier_code' => 'nullable|string|max:50',
        ]);

        $result = $this->orderService->requestAwb($validated);

        return $this->successResponse($result, 'Request AWB berhasil dikirim');
    }

    #[OA\Get(
        path: '/api/v1/sales/unfullfilled',
        summary: 'Get unfulfilled orders (no picklist/packlist)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unfulfilled(Request $request)
    {
        $limit = $request->query('limit', 10);
        $orders = $this->orderService->getUnfulfilledOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order belum fulfill');
    }

    #[OA\Post(
        path: '/api/v1/sales/{id}/items/{itemId}/download',
        summary: 'Download/bind an un-mapped order item to a Master Produk variant',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item mapped successfully'),
            new OA\Response(response: 404, description: 'Order or item not found'),
            new OA\Response(response: 422, description: 'Product not yet in Master Produk'),
        ]
    )]
    public function downloadOrderItem(Request $request, $id, $itemId)
    {
        $order = SalesOrder::findOrFail($id);

        $validated = $request->validate([
            'variant_id' => 'nullable|uuid|exists:product_variants,id',
        ]);

        $order = $this->orderService->downloadOrderItem($order, $itemId, $validated['variant_id'] ?? null);

        return $this->successResponse(new SalesOrderResource($order), 'Produk berhasil di-download dan dipetakan');
    }

    #[OA\Post(
        path: '/api/v1/sales/{id}/accept-cancel',
        summary: 'Accept a cancel request and cancel the order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancel request accepted'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'No pending cancel request'),
        ]
    )]
    public function acceptCancelRequest(string $id, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $order = $this->orderService->acceptCancelRequest($id, auto: false, reason: $validated['reason'] ?? null);

        return $this->successResponse(new SalesOrderResource($order), 'Pembatalan pesanan diterima');
    }

    #[OA\Post(
        path: '/api/v1/sales/{id}/reject-cancel',
        summary: 'Reject a cancel request and keep the order active',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancel request rejected'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'No pending cancel request'),
        ]
    )]
    public function rejectCancelRequest(string $id, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $order = $this->orderService->rejectCancelRequest($id, reason: $validated['reason'] ?? null);

        return $this->successResponse(new SalesOrderResource($order), 'Permintaan pembatalan ditolak');
    }

    #[OA\Get(
        path: '/api/v1/sales/{id}/shipping-label',
        summary: 'Get shipping label / AWB document from marketplace channel',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'doc_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'shipping_label')),
            new OA\Parameter(name: 'document_type', in: 'query', required: false, description: 'TikTok: SHIPPING_LABEL|PACKING_LIST|SHIPPING_LABEL_AND_PACKING_LIST · Shopee: NORMAL_AIR_WAYBILL|THERMAL_AIR_WAYBILL|SELF_DESIGN', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'document_size', in: 'query', required: false, description: 'TikTok: A6|A5|...', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shipping label retrieved'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Channel not supported or missing data'),
        ]
    )]
    public function getShippingLabel(string $id, Request $request)
    {
        $order = SalesOrder::findOrFail($id);

        if ($order->isManual()) {
            return $this->errorResponse(
                'Pesanan manual (Toko Internal) tidak menggunakan label marketplace. Input resi secara manual.',
                422
            );
        }

        $options = array_filter([
            'doc_type'      => $request->query('doc_type'),
            'document_type' => $request->query('document_type'),
            'document_size' => $request->query('document_size'),
        ], fn ($v) => $v !== null && $v !== '');

        $source = strtolower((string) ($order->source ?? ''));
        $capabilities = (array) config("channel_print_capabilities.$source", []);
        $validTypes = array_column($capabilities['document_types'] ?? [], 'value');
        $validSizes = array_column($capabilities['document_sizes'] ?? [], 'value');

        if (isset($options['document_type']) && $validTypes && ! in_array($options['document_type'], $validTypes, true)) {
            return $this->errorResponse(
                'Jenis dokumen tidak didukung channel. Pilih salah satu: ' . implode(', ', $validTypes),
                422
            );
        }
        if (isset($options['document_size']) && $validSizes && ! in_array($options['document_size'], $validSizes, true)) {
            return $this->errorResponse(
                'Ukuran dokumen tidak didukung channel. Pilih salah satu: ' . implode(', ', $validSizes),
                422
            );
        }

        try {
            $result = $this->orderService->getShippingLabel($order, $options);

            return $this->successResponse($result, 'Shipping label berhasil diambil');
        } catch (\Modules\Sales\Exceptions\ShippingLabelPreparingException $e) {
            return $this->errorResponse($e->getMessage(), 202);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function retryShippingLabel(string $id)
    {
        $order = SalesOrder::findOrFail($id);

        if ($order->isManual()) {
            return $this->errorResponse(
                'Pesanan manual tidak menggunakan label marketplace.',
                422
            );
        }

        try {
            $this->orderService->retryShippingLabel($order);

            return $this->successResponse(null, 'Label sedang disiapkan ulang. Coba unduh lagi dalam 1-2 menit.', 202);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function printWithDriverCall(string $id, Request $request, ShopeeOrderService $shopee)
    {
        $order = SalesOrder::findOrFail($id);

        if ($order->isManual()) {
            return $this->errorResponse(
                'Pesanan manual tidak memicu panggilan driver Shopee.',
                422
            );
        }

        if (! ShopeeInstantEligibility::isEligible($order)) {
            return $this->errorResponse(
                'Endpoint ini hanya untuk pesanan Shopee Instant atau Same Day.',
                422
            );
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;
        if ($shopId === '' || $orderSn === '') {
            return $this->errorResponse('channel_shop_id / channel_order_no kosong pada pesanan.', 422);
        }

        $forceLabel = (bool) $request->query('force_label', false);

        $order->update([
            'driver_call_status'       => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        $driverCallSuccess = false;
        $driverCallMessage = null;

        try {
            if ($order->channel_status === 'RETRY_SHIP') {
                $result = $shopee->retryPickup($shopId, $orderSn);
                $shipped = (bool) ($result['updated'] ?? false);
            } else {
                $result = $shopee->shipOrder($shopId, $orderSn);
                $shipped = (bool) ($result['shipped'] ?? false);
            }
            $error = (string) ($result['error'] ?? '');
            $alreadyShipped = $error !== '' && preg_match('/already|duplicate|shipped/i', $error);

            if ($shipped || $alreadyShipped) {
                $driverCallSuccess = true;
                $order->update([
                    'driver_call_status'   => 'success',
                    'driver_call_message'  => null,
                    'driver_call_response' => $result,
                ]);
            } else {
                $driverCallMessage = $error !== '' ? $error : 'ship_order gagal tanpa pesan';
                $order->update([
                    'driver_call_status'   => 'failed',
                    'driver_call_message'  => mb_substr($driverCallMessage, 0, 500),
                    'driver_call_response' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $driverCallMessage = $e->getMessage();
            $order->update([
                'driver_call_status'  => 'failed',
                'driver_call_message' => mb_substr($driverCallMessage, 0, 500),
            ]);
        }

        $order->refresh();

        if (! $driverCallSuccess && ! $forceLabel) {
            return $this->errorResponse('Panggilan driver Shopee gagal. Tambahkan ?force_label=1 untuk tetap mencetak label.', 422, [
                'driver_call_status'       => $order->driver_call_status,
                'driver_call_message'      => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
            ]);
        }

        $options = array_filter([
            'doc_type'      => $request->query('doc_type'),
            'document_type' => $request->query('document_type'),
            'document_size' => $request->query('document_size'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $labelResult = $this->orderService->getShippingLabel($order, $options);
        } catch (\Modules\Sales\Exceptions\ShippingLabelPreparingException $e) {
            return $this->successResponse([
                'driver_call_status'       => $order->driver_call_status,
                'driver_call_message'      => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                'label' => null,
                'label_preparing' => true,
                'label_message' => $e->getMessage(),
            ], 'Driver berhasil dipanggil, label masih disiapkan. Coba unduh dalam beberapa detik.', 202);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->errorResponse('Driver berhasil dipanggil namun label gagal diambil: ' . $e->getMessage(), 422, [
                'success' => $driverCallSuccess,
                'driver_call_status'       => $order->driver_call_status,
                'driver_call_message'      => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                'label' => null,
                'label_error' => $e->getMessage(),
            ]);
        }

        return $this->successResponse([
            'driver_call_status'       => $order->driver_call_status,
            'driver_call_message'      => $order->driver_call_message,
            'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
            'label' => $labelResult,
        ], $driverCallSuccess
            ? 'Driver Shopee terpanggil dan label siap diunduh.'
            : 'Label siap; panggilan driver gagal — silakan retry.');
    }

    public function retryDriverCall(string $id)
    {
        $order = SalesOrder::findOrFail($id);

        if (! ShopeeInstantEligibility::isEligible($order)) {
            return $this->errorResponse('Endpoint ini hanya untuk pesanan Shopee Instant atau Same Day.', 422);
        }

        $order->update([
            'driver_call_status'  => 'pending',
            'driver_call_message' => null,
        ]);

        CallShopeeDriverJob::dispatch($order->id);

        return $this->successResponse([
            'driver_call_status' => 'pending',
        ], 'Panggilan driver dicoba ulang. Status akan diperbarui dalam beberapa detik.', 202);
    }

    #[OA\Put(
        path: '/api/v1/sales/{id}/relocate',
        summary: 'Change the warehouse/location for an order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id'],
            properties: [
                new OA\Property(property: 'location_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order relocated successfully'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function relocate(string $id, Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'required|bail|uuid|exists:locations,id',
        ]);

        $order = SalesOrder::findOrFail($id);
        $order = $this->orderService->relocateOrder($order, $validated['location_id']);

        return $this->successResponse(new SalesOrderResource($order), 'Lokasi pengambilan pesanan berhasil diubah');
    }

    #[OA\Get(
        path: '/api/v1/sales/{id}/invoice',
        summary: 'Generate invoice PDF for a sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Order not found')
        ]
    )]
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/mark-contacted',
        summary: 'Mark that customer has been contacted for an empty-stock order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'channel', type: 'string', nullable: true, enum: ['marketplace_chat', 'whatsapp', 'phone', 'other']),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Pesanan berhasil ditandai sudah dihubungi')]
    )]
    public function markContacted(string $id, Request $request)
    {
        $validated = $request->validate([
            'channel' => 'nullable|string|in:marketplace_chat,whatsapp,phone,other',
            'note'    => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->orderService->markContacted($id, $validated['channel'] ?? null, $validated['note'] ?? null);

            return $this->successResponse(new SalesOrderResource($order), 'Pesanan ditandai sudah dihubungi');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/bulk-mark-contacted',
        summary: 'Bulk mark orders as customer-contacted',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'channel', type: 'string', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Pesanan berhasil ditandai sudah dihubungi')]
    )]
    public function bulkMarkContacted(Request $request)
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|bail|uuid|exists:sales_orders,id',
            'channel'     => 'nullable|string|in:marketplace_chat,whatsapp,phone,other',
            'note'        => 'nullable|string|max:500',
        ]);

        try {
            $count = $this->orderService->bulkMarkContacted(
                $validated['order_ids'],
                $validated['channel'] ?? null,
                $validated['note'] ?? null,
            );

            return $this->successResponse(['contacted' => $count], "{$count} pesanan ditandai sudah dihubungi");
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/customer-decision',
        summary: 'Record buyer decision (waiting/cancel/replace) for an empty-stock order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['decision'],
            properties: [
                new OA\Property(property: 'decision', type: 'string', enum: ['waiting', 'cancel', 'replace']),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Keputusan buyer tersimpan')]
    )]
    public function setCustomerDecision(string $id, Request $request)
    {
        $validated = $request->validate([
            'decision' => 'required|string|in:waiting,cancel,replace',
            'note'     => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->orderService->setCustomerDecision($id, $validated['decision'], $validated['note'] ?? null);

            return $this->successResponse(new SalesOrderResource($order), 'Keputusan buyer tersimpan');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Patch(
        path: '/api/v1/sales/orders/{id}/items/{itemId}',
        summary: 'Update an order item internally (does not sync to marketplace)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sku', type: 'string', nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'qty_in_base', type: 'integer', nullable: true, minimum: 1),
                new OA\Property(property: 'price', type: 'number', nullable: true),
                new OA\Property(property: 'disc_amount', type: 'number', nullable: true),
                new OA\Property(property: 'tax_amount', type: 'number', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Item pesanan diperbarui'),
            new OA\Response(response: 422, description: 'Order tidak dapat diedit'),
        ]
    )]
    public function updateItem(string $id, string $itemId, Request $request)
    {
        $validated = $request->validate([
            'sku'         => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'qty_in_base' => 'nullable|integer|min:1',
            'price'       => 'nullable|numeric|min:0',
            'disc_amount' => 'nullable|numeric|min:0',
            'tax_amount'  => 'nullable|numeric|min:0',
        ]);

        try {
            $order = $this->orderService->updateOrderItem($id, $itemId, $validated);

            return $this->successResponse(new SalesOrderResource($order->load('items')), 'Item pesanan diperbarui (internal)');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/sales/orders/{id}/items/{itemId}',
        summary: 'Delete an order item internally (does not sync to marketplace)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item pesanan dihapus'),
            new OA\Response(response: 422, description: 'Order tidak dapat diedit'),
        ]
    )]
    public function deleteItem(string $id, string $itemId)
    {
        try {
            $order = $this->orderService->deleteOrderItem($id, $itemId);

            return $this->successResponse(new SalesOrderResource($order->load('items')), 'Item pesanan dihapus (internal)');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function invoice(string $id)
    {
        $order = SalesOrder::with('items')->find($id);

        if (! $order) {
            return $this->errorResponse('Pesanan tidak ditemukan', 404);
        }

        $shipping = (object) [
            'full_name' => $order->shipping_full_name,
            'phone'     => $order->shipping_phone,
            'address'   => $order->shipping_address,
            'city'      => $order->shipping_city,
            'province'  => $order->shipping_province,
            'post_code' => $order->shipping_post_code,
        ];
        $order->shipping = $shipping;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('sales::pdf.invoice', [
            'order' => $order,
        ])->setPaper('a4', 'portrait');

        $filename = "INV-{$order->salesorder_no}.pdf";

        return $pdf->stream($filename);
    }

    public function breakdown(string $id)
    {
        $order = SalesOrder::with(['items', 'returns.settlement', 'invoices'])->find($id);

        if (! $order) {
            return $this->errorResponse('Pesanan tidak ditemukan', 404);
        }

        $shipping = (object) [
            'full_name' => $order->shipping_full_name,
            'phone'     => $order->shipping_phone,
            'address'   => $order->shipping_address,
            'city'      => $order->shipping_city,
            'province'  => $order->shipping_province,
            'post_code' => $order->shipping_post_code,
        ];
        $order->shipping = $shipping;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('sales::pdf.order-breakdown', [
            'order' => $order,
        ])->setPaper('a4', 'portrait');

        $filename = "RINCIAN-{$order->salesorder_no}.pdf";

        return $pdf->stream($filename);
    }
}
