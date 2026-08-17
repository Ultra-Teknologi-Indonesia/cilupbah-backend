<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Exports\StockAdjustmentExport;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Http\Requests\StoreStockAdjustmentRequest;
use Modules\Inventory\Http\Resources\StockAdjustmentResource;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Stock Adjustment', description: 'API Endpoints for Document-based Stock Adjustment')]
class StockAdjustmentController extends Controller
{
    public function __construct(
        protected StockAdjustmentService $adjustmentService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents',
        summary: 'Get list of stock adjustment documents',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, description: 'Search by adjustment_no', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar dokumen adjustment berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $adjustments = $this->adjustmentService->getAllPaginated($limit);

        return $this->successPaginatedResponse($adjustments, 'Daftar dokumen adjustment berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents/{id}',
        summary: 'Get stock adjustment document detail',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail adjustment berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $adjustment = $this->adjustmentService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Dokumen adjustment tidak ditemukan.', 404);
        }

        if (!$adjustment) {
            return $this->errorResponse('Dokumen adjustment tidak ditemukan.', 404);
        }

        return $this->successResponse(new StockAdjustmentResource($adjustment), 'Detail adjustment berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents/{id}/items',
        summary: 'Get paginated stock adjustment items',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function items(Request $request, string $id): JsonResponse
    {
        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $items = $this->adjustmentService->getItemsPaginated($id, $limit);

        return $this->successPaginatedResponse($items, 'Daftar item berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents',
        summary: 'Create a new stock adjustment document',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['transaction_date', 'location_id', 'items'],
            properties: [
                new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'is_beginning_balance', type: 'boolean'),
                new OA\Property(property: 'notes', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                        new OA\Property(property: 'actual_qty', type: 'integer'),
                        new OA\Property(property: 'batch_no', type: 'string', nullable: true),
                        new OA\Property(property: 'serial_no', type: 'string', nullable: true),
                        new OA\Property(property: 'notes', type: 'string', nullable: true),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Dokumen adjustment berhasil dibuat.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $adjustment = $this->adjustmentService->create($data);

            return $this->successResponse(new StockAdjustmentResource($adjustment), 'Dokumen adjustment berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Delete(
        path: '/api/v1/inventory/adjustments/documents/{id}',
        summary: 'Delete a stock adjustment document (soft delete)',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumen adjustment berhasil dihapus.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->adjustmentService->delete($id);

            return $this->successResponse(null, 'Dokumen adjustment berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents/{id}/pdf',
        summary: 'Cetak Laporan Penyesuaian sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan'),
        ]
    )]
    public function pdf(string $id)
    {
        try {
            $adjustment = $this->adjustmentService->getForPdf($id);

            if (!$adjustment) {
                return $this->errorResponse('Dokumen adjustment tidak ditemukan.', 404);
            }

            $filename = 'Laporan-Penyesuaian-' . $adjustment->adjustment_no . '.pdf';

            $pdf = Pdf::loadView('inventory::pdf.stock-adjustment', [
                'adjustment' => $adjustment,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF penyesuaian.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents/bulk/pdf',
        summary: 'Cetak banyak Laporan Penyesuaian dalam 1 PDF multi-halaman',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Sebagian dokumen tidak ditemukan'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function bulkPdf(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|string',
        ]);

        try {
            $ids = $validated['ids'];

            $adjustments = $this->adjustmentService->getManyForPdf($ids);

            if ($adjustments->count() !== count($ids)) {
                $foundIds = $adjustments->pluck('id')->all();
                $missing = array_values(array_diff($ids, $foundIds));
                return $this->errorResponse(
                    'Sebagian dokumen tidak ditemukan: ' . implode(', ', $missing),
                    404
                );
            }

            $filename = 'Laporan-Penyesuaian-Bulk-' . now()->format('Ymd-His') . '.pdf';

            $pdf = Pdf::loadView('inventory::pdf.stock-adjustment-bulk', [
                'adjustments' => $adjustments,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF penyesuaian bulk.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents/bulk/delete',
        summary: 'Hapus banyak dokumen penyesuaian sekaligus (soft delete)',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Dokumen dihapus (partial success mungkin terjadi)'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|string',
        ]);

        $deleted = 0;
        $failed = [];

        foreach ($validated['ids'] as $id) {
            try {
                $this->adjustmentService->delete($id);
                $deleted++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $this->successResponse(
            ['deleted' => $deleted, 'failed' => $failed],
            $deleted > 0
                ? "{$deleted} dokumen dihapus" . (count($failed) > 0 ? ", " . count($failed) . " gagal" : "")
                : 'Tidak ada dokumen yang berhasil dihapus.'
        );
    }

    public function exportXlsx(Request $request)
    {
        $query = $this->adjustmentService->getQueryForExport($request);
        $filename = 'koreksi-stok-' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new StockAdjustmentExport($query), $filename);
    }
}
