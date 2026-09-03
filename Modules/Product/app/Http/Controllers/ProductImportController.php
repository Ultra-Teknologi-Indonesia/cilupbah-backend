<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Exports\BundleTemplateExport;
use Modules\Product\Exports\ImportErrorReportExport;
use Modules\Product\Exports\ProductTemplateExport;
use Modules\Product\Http\Resources\ProductImportBatchResource;
use Modules\Product\Http\Resources\ProductImportErrorResource;
use Modules\Product\Http\Resources\ProductImportRowResource;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Models\ProductImportRow;
use Modules\Product\Services\ImportBatchService;

class ProductImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ImportBatchService $batchService,
    ) {}

    public function importSingle(Request $request)
    {
        return $this->queueImport($request, ProductImportBatch::TYPE_SINGLE);
    }

    public function importBundle(Request $request)
    {
        return $this->queueImport($request, ProductImportBatch::TYPE_BUNDLE);
    }

    private function queueImport(Request $request, string $type)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        $batch = $this->batchService->createFromUpload(
            $request->file('file'),
            $type,
            $request->user()?->id
        );

        $this->batchService->queuePreview($batch);

        return $this->successResponse(
            new ProductImportBatchResource($batch),
            'File diterima. Pratinjau sedang diproses di latar belakang.',
            202
        );
    }

    public function batches(Request $request)
    {
        $paginator = $this->batchService->paginateBatches();
        $paginator->through(fn (ProductImportBatch $batch) => new ProductImportBatchResource($batch));

        return $this->successPaginatedResponse($paginator, 'Daftar batch import');
    }

    public function show(string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse(new ProductImportBatchResource($model), 'Detail batch import');
    }

    public function rows(Request $request, string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->batchService->paginateRows($model);
        $paginator->through(fn (ProductImportRow $row) => new ProductImportRowResource($row));

        return $this->successPaginatedResponse($paginator, 'Daftar baris import');
    }

    public function confirm(Request $request, string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        if (! $this->batchService->confirmBatch($model, $request->user()?->id)) {
            return $this->errorResponse('Batch sedang atau sudah diproses.', 422, null, 'Aksi tidak dapat diproses');
        }

        return $this->successResponse(
            new ProductImportBatchResource($model->fresh()),
            'Penerapan sedang diproses di latar belakang.',
            202,
        );
    }

    public function errors(Request $request, string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->batchService->paginateErrors($model);
        $paginator->through(fn ($error) => new ProductImportErrorResource($error));

        return $this->successPaginatedResponse($paginator, 'Daftar error batch import');
    }

    public function downloadErrors(string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $content = Excel::raw(new ImportErrorReportExport($model), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"import-errors-{$model->batch_no}.xlsx\"",
            'Content-Length' => \strlen($content),
        ]);
    }

    public function downloadSingleTemplate()
    {
        $content = Excel::raw(new ProductTemplateExport(), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template_Import_Product.xlsx"',
            'Content-Length' => \strlen($content),
        ]);
    }

    public function downloadBundleTemplate()
    {
        $content = Excel::raw(new BundleTemplateExport(), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template_Import_Bundle.xlsx"',
            'Content-Length' => \strlen($content),
        ]);
    }

    private function batchPayload(ProductImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'type' => $batch->type,
            'state' => $batch->state,
            'original_filename' => $batch->original_filename,
            'total_rows' => $batch->total_rows,
            'processed_rows' => $batch->processed_rows,
            'success_rows' => $batch->success_rows,
            'failed_rows' => $batch->failed_rows,
            'progress_percent' => $batch->progress_percent,
            'error_message' => $batch->error_message,
            'created_at' => $batch->created_at,
        ];
    }
}
