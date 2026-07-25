<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Inventory\Services\TransferOutImportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferOutImportController extends Controller
{
    public function __construct(
        protected TransferOutImportService $importService,
        protected ImpexActivityService $activityService,
    ) {}

    public function template(): StreamedResponse
    {
        $spreadsheet = $this->importService->generateTemplate();

        $filename = 'template-import-transfer-keluar.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
        ]);

        try {
            $result = $this->importService->preview($request->file('file'));

            return $this->successResponse($result, 'Preview import berhasil di-generate.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memuat detail.',
                422,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'preview_token' => 'required|string',
            'created_by'    => 'required|string|max:100',
        ]);

        $activity = $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Transfer Keluar',
            $request->user()?->id,
        );

        try {
            $result = $this->importService->confirm(
                $request->input('preview_token'),
                $request->input('created_by'),
            );

            if ($result['failed'] > 0 && $result['created'] === 0) {
                $this->activityService->markFailed($activity, implode('; ', $result['errors']));
            } else {
                $this->activityService->markSuccess($activity);
            }

            return $this->successResponse($result, 'Import transfer keluar selesai.');
        } catch (\Exception $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            return $this->errorResponse(
                'Gagal menyetujui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
