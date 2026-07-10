<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Inventory\Services\StockAdjustmentImportService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Http\Resources\StockAdjustmentResource;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentImportController extends Controller
{
    public function __construct(
        protected StockAdjustmentImportService $importService,
        protected StockAdjustmentService $adjustmentService,
        protected ImpexActivityService $activityService,
    ) {}

    public function template(): StreamedResponse
    {
        $spreadsheet = $this->importService->generateTemplate();

        $filename = 'template-import-penyesuaian-stok.xlsx';

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
            'file'        => 'required|file|mimes:xlsx|max:5120',
            'location_id' => 'required|string',
        ]);

        try {
            $result = $this->importService->preview(
                $request->file('file'),
                $request->input('location_id'),
            );

            return $this->successResponse($result, 'Preview import berhasil di-generate.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'preview_token'    => 'required|string',
            'transaction_date' => 'required|date',
            'created_by'       => 'required|string',
            'notes'            => 'nullable|string',
            'adjustment_no'    => 'nullable|string|max:100',
        ]);

        $preview = $this->importService->getPreview($request->input('preview_token'));

        if (! $preview) {
            return $this->errorResponse('Preview token expired atau tidak ditemukan. Silakan upload ulang.', 422);
        }

        if (empty($preview['items'])) {
            return $this->errorResponse('Tidak ada item valid untuk di-import.', 422);
        }

        $activity = $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Penyesuaian Stok',
            $request->user()?->id,
        );

        try {
            $adjustment = $this->adjustmentService->create([
                'adjustment_no'    => $request->input('adjustment_no') ?: null,
                'transaction_date' => $request->input('transaction_date'),
                'location_id'      => $preview['location_id'],
                'notes'            => $request->input('notes'),
                'created_by'       => $request->input('created_by'),
                'items' => array_map(fn ($it) => [
                    'item_id'   => $it['item_id'],
                    'bin_id'    => $it['bin_id'],
                    'actual_qty' => $it['actual_qty'],
                    'unit_cost' => $it['unit_cost'],
                    'notes'     => $it['notes'],
                ], $preview['items']),
            ]);

            $this->importService->forgetPreview($request->input('preview_token'));
            $this->activityService->markSuccess($activity);

            return $this->successResponse(new StockAdjustmentResource($adjustment), 'Import penyesuaian stok berhasil diterapkan.');
        } catch (\Exception $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
