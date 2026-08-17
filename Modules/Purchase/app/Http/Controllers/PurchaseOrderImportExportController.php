<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseOrderExportService;
use Modules\Purchase\Services\PurchaseOrderImportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // max 10MB
        ]);

        try {
            $result = $this->importService->preview($request->file('file'));
            return $this->successResponse($result, 'Preview import pesanan pembelian berhasil dibuat.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'preview_token' => 'required|string',
        ]);

        try {
            $createdBy = auth()->user()?->name ?? 'system';
            $result = $this->importService->confirm($request->input('preview_token'), $createdBy);

            $msg = sprintf(
                'Import selesai: %d pesanan berhasil dibuat%s.',
                $result['created'],
                $result['failed'] > 0 ? ", {$result['failed']} gagal" : ''
            );

            return $this->successResponse($result, $msg);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function exportList(Request $request): StreamedResponse
    {
        $filters = [
            'location_id' => $request->query('location_id') ?: $request->query('filter.location_id'),
            'contact_id'  => $request->query('contact_id') ?: $request->query('filter.contact_id'),
            'status'      => $request->query('status') ?: $request->query('filter.status'),
            'date_from'   => $request->query('date_from') ?: $request->query('filter.date_from'),
            'date_to'     => $request->query('date_to') ?: $request->query('filter.date_to'),
            'search'      => $request->query('search'),
        ];

        return $this->exportService->streamList($filters);
    }

    public function exportDetail(Request $request): StreamedResponse
    {
        $filters = [
            'location_id' => $request->query('location_id') ?: $request->query('filter.location_id'),
            'contact_id'  => $request->query('contact_id') ?: $request->query('filter.contact_id'),
            'status'      => $request->query('status') ?: $request->query('filter.status'),
            'date_from'   => $request->query('date_from') ?: $request->query('filter.date_from'),
            'date_to'     => $request->query('date_to') ?: $request->query('filter.date_to'),
            'search'      => $request->query('search'),
        ];

        return $this->exportService->streamDetail($filters);
    }
}
