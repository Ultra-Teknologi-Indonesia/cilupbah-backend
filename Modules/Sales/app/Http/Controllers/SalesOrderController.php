<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfRenderer;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Sales\Enums\BuyerCancellationSyncStatus;
use Modules\Sales\Exports\CancelledOrdersExport;
use Modules\Sales\Exports\SalesOrdersExport;
use Modules\Sales\Http\Requests\AcceptOrderCancelRequest;
use Modules\Sales\Http\Requests\BulkCancelManualOrderRequest;
use Modules\Sales\Http\Requests\BulkMarkContactedRequest;
use Modules\Sales\Http\Requests\CancelManualOrderRequest;
use Modules\Sales\Http\Requests\DeleteCanceledOrdersRequest;
use Modules\Sales\Http\Requests\DownloadOrderItemRequest;
use Modules\Sales\Http\Requests\ExportCancelledOrdersRequest;
use Modules\Sales\Http\Requests\ExportSalesOrdersRequest;
use Modules\Sales\Http\Requests\MarkContactedRequest;
use Modules\Sales\Http\Requests\MarkOrdersCompleteRequest;
use Modules\Sales\Http\Requests\MoveToReadyToProcessRequest;
use Modules\Sales\Http\Requests\RejectOrderCancelRequest;
use Modules\Sales\Http\Requests\RelocateOrderRequest;
use Modules\Sales\Http\Requests\RequestAwbRequest;
use Modules\Sales\Http\Requests\RequestChannelCancelRequest;
use Modules\Sales\Http\Requests\SaveAirwaybillRequest;
use Modules\Sales\Http\Requests\SaveCourierPickupRequest;
use Modules\Sales\Http\Requests\SaveReceivedDateRequest;
use Modules\Sales\Http\Requests\SetCustomerDecisionRequest;
use Modules\Sales\Http\Requests\SetOrderAsPaidRequest;
use Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use Modules\Sales\Http\Requests\UpdateOrderItemRequest;
use Modules\Sales\Http\Requests\UpdateSalesOrderRequest;
use Modules\Sales\Http\Requests\UploadCourierIdPhotoRequest;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Http\Resources\ShippingLabelResource;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderDriverCallService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Support\OrderPdfPresenter;
use OpenApi\Attributes as OA;

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
        ),
    ]
)]
class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SalesOrderService $orderService,
        protected ImpexActivityService $activityService,
        protected PdfRenderer $pdf,
    ) {}

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
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesOrder')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request)
    {
        $orders = $this->orderService->getPaginatedOrders();
        $orders->getCollection()->transform(function ($order) {
            return new SalesOrderResource($order);
        });

        return $this->successPaginatedResponse($orders);
    }

    public function counts()
    {
        return $this->successResponse($this->orderService->getTabCounts());
    }

    public function shippingProviders(Request $request)
    {
        $providers = $this->orderService->getShippingProviders($request->all());

        return $this->successResponse($providers);
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
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSalesOrderRequest $request)
    {
        $order = $this->orderService->createOrder($request->validated());

        return $this->successResponse(new SalesOrderResource($order), 'Sales order created', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/{id}',
        summary: 'Get sales order details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found'),
        ]
    )]
    public function show(Request $request, $id)
    {
        $order = $this->orderService->getOrderById($id);
        if (! $order) {
            return $this->errorResponse('Data tidak ditemukan', 404);
        }

        return $this->successResponse(new SalesOrderResource($order));
    }

    #[OA\Put(
        path: '/api/v1/sales/{id}',
        summary: 'Update an existing sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order to update', schema: new OA\Schema(type: 'string')),
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
                        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function update(UpdateSalesOrderRequest $request, $id)
    {
        $order = $this->orderService->findOrderOrFail($id);
        $order = $this->orderService->updateOrder($order, $request->validated());

        return $this->successResponse(new SalesOrderResource($order), 'Sales order updated');
    }

    #[OA\Delete(
        path: '/api/v1/sales/{id}',
        summary: 'Delete a sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the sales order to delete', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales order deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Sales order not found'),
        ]
    )]
    public function destroy($id)
    {
        $this->orderService->deleteOrderById($id);

        return $this->successResponse(null, 'Sales order deleted');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/cancel',
        summary: 'Get cancelled orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function cancelled(Request $request)
    {
        $limit = $request->query('per_page', 20);
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
    public function export(ExportSalesOrdersRequest $request)
    {
        $validated = $request->validated();

        $export = new SalesOrdersExport(
            tab: $validated['tab'] ?? null,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            source: $validated['source'] ?? null,
            search: $validated['search'] ?? null,
            storeId: $validated['store_id'] ?? null,
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

    public function exportCancelled(ExportCancelledOrdersRequest $request)
    {
        $validated = $request->validated();

        $export = new CancelledOrdersExport(
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            postPackOnly: (bool) ($validated['post_pack_only'] ?? false),
            source: $validated['source'] ?? null,
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
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function completed(Request $request)
    {
        $limit = $request->query('per_page', 20);
        $orders = $this->orderService->getCompletedOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order completed');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/failed',
        summary: 'Get failed orders',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function failed(Request $request)
    {
        $limit = $request->query('per_page', 20);
        $orders = $this->orderService->getFailedOrders($limit);

        return $this->successPaginatedResponse($orders, 'Daftar order failed');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/returned-list',
        summary: 'Get orders with returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function returnedList(Request $request)
    {
        $limit = $request->query('per_page', 20);
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
    public function deleteCanceled(DeleteCanceledOrdersRequest $request)
    {
        $count = $this->orderService->bulkDeleteCancelled($request->validated()['ids']);

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
    public function moveToReadyToProcess(MoveToReadyToProcessRequest $request)
    {
        $result = $this->orderService->moveToReadyToProcess($request->validated()['order_ids'], $request->user());

        return $this->successResponse([
            'moved' => $result['moved'],
            'skipped' => $result['skipped'],
        ], $result['message']);
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
    public function markAsComplete(MarkOrdersCompleteRequest $request)
    {
        $count = $this->orderService->markAsComplete($request->validated()['order_ids']);

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
    public function saveAirwaybill(SaveAirwaybillRequest $request)
    {
        $order = $this->orderService->saveAirwaybill($request->validated());

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
    public function saveReceivedDate(SaveReceivedDateRequest $request)
    {
        $order = $this->orderService->saveReceivedDate($request->validated());

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
    public function uploadCourierIdPhoto(UploadCourierIdPhotoRequest $request, string $id)
    {
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
    public function setAsPaid(SetOrderAsPaidRequest $request)
    {
        $order = $this->orderService->setAsPaid($request->validated());

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
    public function requestAwb(RequestAwbRequest $request)
    {
        $result = $this->orderService->requestAwb($request->validated());

        return $this->successResponse($result, 'Request AWB berhasil dikirim');
    }

    #[OA\Get(
        path: '/api/v1/sales/unfullfilled',
        summary: 'Get unfulfilled orders (no picklist/packlist)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unfulfilled(Request $request)
    {
        $limit = $request->query('per_page', 20);
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
    public function downloadOrderItem(DownloadOrderItemRequest $request, $id, $itemId)
    {
        $order = $this->orderService->findOrderOrFail($id);
        $order = $this->orderService->downloadOrderItem($order, $itemId, $request->validated()['variant_id'] ?? null);

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
    public function acceptCancelRequest(string $id, AcceptOrderCancelRequest $request)
    {
        try {
            $order = $this->orderService->acceptCancelRequest($id, auto: false, reason: $request->validated()['reason'] ?? null);
        } catch (\Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            $order = SalesOrder::find($id);

            return $this->errorResponse(
                'Pembatalan lokal sudah diproses, tetapi keputusan belum berhasil dikirim ke channel. Silakan coba kirim ulang.',
                422,
                $order ? [
                    'buyer_cancel_sync_status' => $order->buyer_cancel_sync_status,
                    'buyer_cancel_sync_status_label' => $order->buyer_cancel_sync_status
                        ? BuyerCancellationSyncStatus::tryFrom($order->buyer_cancel_sync_status)?->label()
                        : null,
                    'buyer_cancel_sync_error' => $order->buyer_cancel_sync_error,
                ] : null,
            );
        }

        return $this->successResponse(new SalesOrderResource($order), 'Pembatalan buyer diterima dan sudah dikonfirmasi ke channel');
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
    public function rejectCancelRequest(string $id, RejectOrderCancelRequest $request)
    {
        try {
            $order = $this->orderService->rejectCancelRequest($id, reason: $request->validated()['reason'] ?? null);
        } catch (\Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            $order = SalesOrder::find($id);

            return $this->errorResponse(
                'Penolakan lokal sudah diproses, tetapi keputusan belum berhasil dikirim ke channel. Silakan coba kirim ulang.',
                422,
                $order ? [
                    'buyer_cancel_sync_status' => $order->buyer_cancel_sync_status,
                    'buyer_cancel_sync_status_label' => $order->buyer_cancel_sync_status
                        ? BuyerCancellationSyncStatus::tryFrom($order->buyer_cancel_sync_status)?->label()
                        : null,
                    'buyer_cancel_sync_error' => $order->buyer_cancel_sync_error,
                ] : null,
            );
        }

        return $this->successResponse(new SalesOrderResource($order), 'Pembatalan buyer ditolak dan sudah dikonfirmasi ke channel');
    }

    public function retryBuyerCancellationSync(string $id)
    {
        try {
            $order = $this->orderService->retryBuyerCancellationSync($id);
        } catch (\Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                throw $e;
            }

            $order = SalesOrder::find($id);

            return $this->errorResponse(
                'Keputusan belum berhasil dikirim ulang ke channel.',
                422,
                $order ? [
                    'buyer_cancel_sync_status' => $order->buyer_cancel_sync_status,
                    'buyer_cancel_sync_status_label' => $order->buyer_cancel_sync_status
                        ? BuyerCancellationSyncStatus::tryFrom($order->buyer_cancel_sync_status)?->label()
                        : null,
                    'buyer_cancel_sync_error' => $order->buyer_cancel_sync_error,
                ] : null,
            );
        }

        return $this->successResponse(new SalesOrderResource($order), 'Keputusan pembatalan buyer berhasil dikirim ulang ke channel');
    }

    #[OA\Post(
        path: '/api/v1/sales/{id}/request-cancel',
        summary: 'Seller mengajukan pembatalan order ke marketplace (TikTok/Shopee/Lazada)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', description: 'Shopee/TikTok: reason key; Lazada: reason_id numerik (live)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permintaan pembatalan dikirim'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Status/alasan tidak valid'),
        ]
    )]
    public function requestChannelCancel(string $id, RequestChannelCancelRequest $request)
    {
        $order = $this->orderService->requestChannelCancel($id, $request->validated()['reason']);

        return $this->successResponse(new SalesOrderResource($order), 'Permintaan pembatalan dikirim ke marketplace');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/cancel-manual',
        summary: 'Membatalkan pesanan manual secara langsung tanpa lewat channel/marketplace',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', description: 'Alasan pembatalan (opsional)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pesanan berhasil dibatalkan'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Order bukan manual atau status tidak valid'),
        ]
    )]
    public function cancelManual(string $id, CancelManualOrderRequest $request)
    {
        $order = SalesOrder::findOrFail($id);

        if ($order->source && ! in_array(strtolower($order->source), ['manual', 'offline'])) {
            return $this->errorResponse('Hanya pesanan manual yang dapat dibatalkan melalui rute ini', 422);
        }

        $canceledOrder = $this->orderService->cancelLocally($id, $request->validated('reason'), Auth::id());

        return $this->successResponse(new SalesOrderResource($canceledOrder), 'Pesanan berhasil dibatalkan secara langsung');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/bulk-cancel-manual',
        summary: 'Membatalkan beberapa pesanan manual secara langsung',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'reason', type: 'string', description: 'Alasan pembatalan (opsional)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pesanan berhasil dibatalkan'),
            new OA\Response(response: 422, description: 'Terdapat pesanan non-manual'),
        ]
    )]
    public function bulkCancelManual(BulkCancelManualOrderRequest $request)
    {
        $data = $request->validated();
        $orders = SalesOrder::whereIn('id', $data['order_ids'])->get();

        foreach ($orders as $order) {
            if ($order->source && ! in_array(strtolower($order->source), ['manual', 'offline'])) {
                return $this->errorResponse("Pesanan {$order->salesorder_no} bukan pesanan manual dan tidak dapat dibatalkan di sini", 422);
            }
        }

        $actorId = Auth::id();
        foreach ($orders as $order) {
            $this->orderService->cancelLocally($order->id, $data['reason'] ?? 'Dibatalkan massal', $actorId);
        }

        return $this->successResponse(null, count($orders).' pesanan berhasil dibatalkan');
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/{id}/cancel-reasons',
        summary: 'Daftar alasan pembatalan yang valid untuk order ini (sumber kebenaran tunggal)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function cancelReasons(string $id)
    {
        return $this->successResponse(
            $this->orderService->cancelReasonsForOrderId($id),
            'Daftar alasan pembatalan',
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/release-cancel',
        summary: 'Lepas hold pembatalan marketplace agar order bisa diproses lagi',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hold pembatalan dilepas'),
            new OA\Response(response: 422, description: 'Pesanan sudah dibatalkan'),
        ]
    )]
    public function releaseChannelCancel(string $id)
    {
        $order = $this->orderService->releaseChannelCancel($id);

        return $this->successResponse(new SalesOrderResource($order), 'Hold pembatalan dilepas, pesanan bisa diproses');
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
        $order = $this->orderService->findOrderOrFail($id);

        $result = $this->orderService->prepareShippingLabelDocument($order, $request->query('document_size'));

        $this->orderService->logLabelPrinted($order, $request->user(), $result->docType ?? null);

        return $this->successResponse(new ShippingLabelResource($result), 'Shipping label berhasil diambil');
    }

    public function retryShippingLabel(string $id)
    {
        $order = $this->orderService->findOrderOrFail($id);

        $this->orderService->resendShippingLabel($order);

        return $this->successResponse(null, 'Label sedang disiapkan ulang. Coba unduh lagi dalam 1-2 menit.', 202);
    }

    public function printWithDriverCall(string $id, Request $request, SalesOrderDriverCallService $driverCall)
    {
        $order = $driverCall->findOrder($id);

        $result = $driverCall->dispatchPrintWithDriverCall($order, $request->query());

        $this->orderService->logLabelPrinted($order, $request->user());

        return $this->successResponse($result['data'], $result['message'], $result['code']);
    }

    public function retryDriverCall(string $id, SalesOrderDriverCallService $driverCall)
    {
        $order = $driverCall->findOrder($id);

        $result = $driverCall->dispatchRetryDriverCall($order);

        return $this->successResponse($result['data'], $result['message'], $result['code']);
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
    public function relocate(string $id, RelocateOrderRequest $request)
    {
        $order = $this->orderService->findOrderOrFail($id);
        $order = $this->orderService->relocateOrder($order, $request->validated()['location_id']);

        return $this->successResponse(new SalesOrderResource($order), 'Lokasi pengambilan pesanan berhasil diubah');
    }

    #[OA\Get(
        path: '/api/v1/sales/{id}/invoice',
        summary: 'Generate invoice PDF for a sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Order not found'),
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
    public function markContacted(string $id, MarkContactedRequest $request)
    {
        $validated = $request->validated();

        try {
            $order = $this->orderService->markContacted($id, $validated['channel'] ?? null, $validated['note'] ?? null);

            return $this->successResponse(new SalesOrderResource($order), 'Pesanan ditandai sudah dihubungi');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'Gagal memproses kontak.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
    public function bulkMarkContacted(BulkMarkContactedRequest $request)
    {
        $validated = $request->validated();

        try {
            $count = $this->orderService->bulkMarkContacted(
                $validated['order_ids'],
                $validated['channel'] ?? null,
                $validated['note'] ?? null,
            );

            return $this->successResponse(['contacted' => $count], "{$count} pesanan ditandai sudah dihubungi");
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'Gagal memproses batch.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
    public function setCustomerDecision(string $id, SetCustomerDecisionRequest $request)
    {
        $validated = $request->validated();

        try {
            $order = $this->orderService->setCustomerDecision($id, $validated['decision'], $validated['note'] ?? null);

            return $this->successResponse(new SalesOrderResource($order), 'Keputusan buyer tersimpan');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
    public function updateItem(string $id, string $itemId, UpdateOrderItemRequest $request)
    {
        try {
            $order = $this->orderService->updateOrderItem($id, $itemId, $request->validated());

            return $this->successResponse(new SalesOrderResource($order->load('items')), 'Item pesanan diperbarui (internal)');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function invoice(string $id)
    {
        $order = $this->orderService->getOrderForInvoice($id);

        if (! $order) {
            return $this->errorResponse('Pesanan tidak ditemukan', 404);
        }

        $order = OrderPdfPresenter::withShipping($order);

        return $this->pdf->stream('sales::pdf.invoice', ['order' => $order], "INV-{$order->salesorder_no}.pdf");
    }

    public function breakdown(string $id)
    {
        $order = $this->orderService->getOrderForBreakdown($id);

        if (! $order) {
            return $this->errorResponse('Pesanan tidak ditemukan', 404);
        }

        $order = OrderPdfPresenter::withShipping($order);

        return $this->pdf->stream('sales::pdf.order-breakdown', ['order' => $order], "RINCIAN-{$order->salesorder_no}.pdf");
    }
}
