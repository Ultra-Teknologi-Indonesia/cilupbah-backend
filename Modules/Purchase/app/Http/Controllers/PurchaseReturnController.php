<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseReturnService;
use Modules\Purchase\Http\Requests\StorePurchaseReturnRequest;
use Modules\Purchase\Http\Resources\PurchaseReturnResource;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Purchase Returns', description: 'API Endpoints for Purchase Returns')]
class PurchaseReturnController extends Controller
{
    public function __construct(
        protected PurchaseReturnService $returnService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/purchase-returns',
        summary: 'Get list of purchase returns',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[supplier_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->returnService->getAllPaginated($limit), 'Daftar purchase return berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/purchase/purchase-returns',
        summary: 'Create a new purchase return',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['supplier_id', 'location_id', 'return_date', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'supplier_id', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'purchase_order_id', type: 'string'),
                new OA\Property(property: 'return_date', type: 'string', format: 'date'),
                new OA\Property(property: 'reason', type: 'string'),
                new OA\Property(property: 'created_by', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'qty', type: 'integer'),
                        new OA\Property(property: 'unit_price', type: 'number'),
                        new OA\Property(property: 'condition', type: 'string'),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Purchase return berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        try {
            $return = $this->returnService->create($request->validated());
            return $this->successResponse($return, 'Purchase return berhasil dibuat', 201);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/purchase-returns/{id}',
        summary: 'Get purchase return details',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Purchase return tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);
        if (! $return) {
            return $this->errorResponse('Purchase return tidak ditemukan', 404);
        }
        return $this->successResponse(new PurchaseReturnResource($return), 'Detail purchase return berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/purchase/purchase-returns/{id}/pdf',
        summary: 'Cetak dokumen Retur Pembelian sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Purchase return tidak ditemukan'),
        ]
    )]
    public function pdf(string $id)
    {
        $return = $this->returnService->getById($id);
        if (! $return) {
            return $this->errorResponse('Purchase return tidak ditemukan', 404);
        }

        try {
            $filename = "{$return->return_number}.pdf";

            $pdf = Pdf::loadView('purchase::pdf.purchase-return', [
                'return' => $return,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse('Gagal membuat PDF retur pembelian: ' . $e->getMessage(), 500);
        }
    }

    public function process(string $id, Request $request): JsonResponse
    {
        try {
            $request->validate(['processed_by' => 'required|string']);
            $return = $this->returnService->process($id, $request->input('processed_by'));
            return $this->successResponse($return, 'Retur pembelian berhasil diproses');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/purchase-returns/unpaid',
        summary: 'Get unpaid purchase returns',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        parameters: [new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->returnService->getUnpaid($limit), 'Daftar purchase return belum lunas');
    }

    #[OA\Delete(
        path: '/api/v1/purchase',
        summary: 'Delete a draft purchase return',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Returns'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [new OA\Property(property: 'id', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Purchase return berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->returnService->delete($request->input('id'));
            return $this->successResponse(null, 'Purchase return berhasil dihapus');
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
