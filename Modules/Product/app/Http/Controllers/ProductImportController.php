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
use Modules\Product\Jobs\ProcessProductImportJob;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Services\ImportBatchService;

class ProductImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ImportBatchService $batchService,
        private ImpexActivityService $activityService,
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
        $query = ProductImportBatch::query()->latest();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($state = $request->query('state')) {
            $query->where('state', $state);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 25))->appends($request->query());

        return $this->successResponse(
            collect($paginator->items())->map(fn ($b) => $this->batchPayload($b))->all(),
            'Daftar batch import',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function show(string $batch)
    {
        $model = ProductImportBatch::find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse($this->batchPayload($model), 'Detail batch import');
    }

    public function errors(Request $request, string $batch)
    {
        $model = ProductImportBatch::find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $model->errors()->orderBy('row_number')
            ->paginate((int) $request->query('per_page', 50))->appends($request->query());

        return $this->successResponse(
            collect($paginator->items())->map(fn ($e) => [
                'row_number' => $e->row_number,
                'attribute' => $e->attribute,
                'message' => $e->message,
                'row_snapshot' => $e->row_snapshot,
            ])->all(),
            'Daftar error batch import',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function downloadErrors(string $batch)
    {
        $model = ProductImportBatch::find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return Excel::download(
            new ImportErrorReportExport($model),
            "import-errors-{$model->batch_no}.xlsx"
        );
    }

    public function downloadSingleTemplate()
    {
        return Excel::download(new ProductTemplateExport(), 'Template_Import_Product.xlsx');
    }

    public function downloadBundleTemplate()
    {
        return Excel::download(new BundleTemplateExport(), 'Template_Import_Bundle.xlsx');
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
