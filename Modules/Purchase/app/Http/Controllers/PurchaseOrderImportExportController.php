<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Http\Requests\ExportPurchaseOrderRequest;
use Modules\Purchase\Http\Requests\ImportPurchaseOrderConfirmRequest;
use Modules\Purchase\Http\Requests\ImportPurchaseOrderPreviewRequest;
use Modules\Purchase\Http\Resources\PurchaseOrderImportConfirmResource;
use Modules\Purchase\Http\Resources\PurchaseOrderImportPreviewResource;
use Modules\Purchase\Services\PurchaseOrderExportService;
use Modules\Purchase\Services\PurchaseOrderImportService;
use Modules\Report\Services\ExportManager;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderImportExportController extends Controller
{
    public function __construct(
        protected PurchaseOrderImportService $importService,
        protected PurchaseOrderExportService $exportService,
    ) {}

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = $this->importService->generateTemplate();
        $filename = 'template-import-pesanan-pembelian.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function preview(ImportPurchaseOrderPreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->importService->preview(
                $request->file('file'),
                $request->user()?->id
            );

            return $this->successResponse(
                new PurchaseOrderImportPreviewResource($result),
                'Pratinjau import pesanan pembelian berhasil diproses.'
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['detail' => $e->getMessage()],
                'Gagal Memproses Pratinjau Import'
            );
        }
    }

    public function confirm(ImportPurchaseOrderConfirmRequest $request): JsonResponse
    {
        try {
            $createdBy = $request->user()?->name ?? 'system';
            $result = $this->importService->confirm($request->input('preview_token'), $createdBy);

            $msg = sprintf(
                'Import selesai: %d pesanan berhasil dibuat%s.',
                $result['created'],
                $result['failed'] > 0 ? ", {$result['failed']} gagal" : ''
            );

            return $this->successResponse(
                new PurchaseOrderImportConfirmResource($result),
                $msg
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['detail' => $e->getMessage()],
                'Gagal Mengonfirmasi Import Pesanan'
            );
        }
    }

    public function exportList(ExportPurchaseOrderRequest $request): StreamedResponse
    {
        return $this->exportService->streamList(
            $request->validated(),
            $request->user()?->id
        );
    }

    public function exportDetail(ExportPurchaseOrderRequest $request): StreamedResponse
    {
        return $this->exportService->streamDetail(
            $request->validated(),
            $request->user()?->id
        );
    }

    public function exportListAsync(ExportPurchaseOrderRequest $request, ExportManager $exports): JsonResponse
    {
        $job = $exports->queue($request->user(), 'purchase-order-list', $request->validated());

        return $this->successResponse(
            ['export_id' => $job->id, 'status' => $job->status],
            null,
            202,
        );
    }

    public function exportDetailAsync(ExportPurchaseOrderRequest $request, ExportManager $exports): JsonResponse
    {
        $job = $exports->queue($request->user(), 'purchase-order-detail', $request->validated());

        return $this->successResponse(
            ['export_id' => $job->id, 'status' => $job->status],
            null,
            202,
        );
    }
}
