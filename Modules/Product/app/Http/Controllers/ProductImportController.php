<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Product\Exports\BundleTemplateExport;
use Modules\Product\Exports\ImportErrorReportExport;
use Modules\Product\Exports\ProductTemplateExport;
use Modules\Product\Http\Resources\ProductImportBatchResource;
use Modules\Product\Http\Resources\ProductImportErrorResource;
use Modules\Product\Jobs\ProcessProductImportJob;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Repositories\ProductImportBatchRepository;
use Modules\Product\Services\ImportBatchService;

class ProductImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ImportBatchService $batchService,
        private ImpexActivityService $activityService,
        private ProductImportBatchRepository $batchRepository,
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

        $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            $type === ProductImportBatch::TYPE_BUNDLE ? 'Import Produk Bundle' : 'Import Produk',
            $request->user()?->id,
            null,
            'product_import_batch',
            $batch->id,
        );

        ProcessProductImportJob::dispatch($batch->id);

        return $this->successResponse(
            $this->batchPayload($batch),
            'File diterima. Import sedang diproses di latar belakang.',
            202
        );
    }

    public function batches(Request $request)
    {
        $paginator = $this->batchRepository->paginateBatches();
        $paginator->through(fn (ProductImportBatch $batch) => new ProductImportBatchResource($batch));

        return $this->successPaginatedResponse($paginator, 'Daftar batch import');
    }

    public function show(string $batch)
    {
        $model = $this->batchRepository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse(new ProductImportBatchResource($model), 'Detail batch import');
    }

    public function errors(Request $request, string $batch)
    {
        $model = $this->batchRepository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->batchRepository->paginateErrors($model);
        $paginator->through(fn ($error) => new ProductImportErrorResource($error));

        return $this->successPaginatedResponse($paginator, 'Daftar error batch import');
    }

    public function downloadErrors(string $batch)
    {
        $model = $this->batchRepository->find($batch);
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
