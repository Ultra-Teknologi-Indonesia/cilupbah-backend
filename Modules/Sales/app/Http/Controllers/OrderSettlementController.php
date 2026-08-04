<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exports\SettlementReportExport;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Repositories\OrderSettlementRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Order Settlements', description: 'Settlement marketplace per-pesanan (read-only)')]
class OrderSettlementController extends Controller
{
    public function __construct(
        protected OrderSettlementRepository $repository,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/settlements',
        summary: 'Daftar settlement marketplace per pesanan',
        security: [['bearerAuth' => []]],
        tags: ['Order Settlements'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel_shop_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[is_settled]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[date_from]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'filter[date_to]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'filter[settled_from]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'filter[settled_to]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(): JsonResponse
    {
        $orders = $this->repository->getPaginated();

        $orders->getCollection()->transform(fn ($order) => new SalesOrderResource($order));

        return $this->successPaginatedResponse($orders, 'Daftar settlement berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/settlements/summary',
        summary: 'Ringkasan agregat settlement (KPI) untuk filter aktif',
        security: [['bearerAuth' => []]],
        tags: ['Order Settlements'],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function summary(): JsonResponse
    {
        return $this->successResponse(
            $this->repository->summary(),
            'Ringkasan settlement berhasil diambil',
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/settlements/export',
        summary: 'Unduh laporan settlement (XLSX) sesuai filter aktif',
        security: [['bearerAuth' => []]],
        tags: ['Order Settlements'],
        responses: [new OA\Response(response: 200, description: 'File XLSX')]
    )]
    public function export()
    {
        $orders = $this->repository->query()->get();

        $filename = 'laporan-settlement-' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new SettlementReportExport($orders), $filename);
    }
}
