<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Sales\Exports\SalesOrderImportErrorReportExport;
use Modules\Sales\Exports\SalesOrderImportTemplateExport;
use Modules\Sales\Jobs\ProcessSalesOrderImportJob;
use Modules\Sales\Models\SalesOrderImportBatch;
use Modules\Sales\Services\SalesOrderImportBatchService;

class SalesOrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SalesOrderImportBatchService $batchService,
        private ImpexActivityService $activityService,
    ) {}

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        $batch = $this->batchService->createFromUpload(
            $request->file('file'),
            $request->user()?->id,
        );

        $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Pesanan',
            $request->user()?->id,
            null,
            'sales_order_import_batch',
            $batch->id,
        );

        ProcessSalesOrderImportJob::dispatch($batch->id);

        return $this->successResponse(
            $this->batchPayload($batch),
            'File diterima. Import sedang diproses di latar belakang.',
            202
        );
    }

    public function batches(Request $request)
    {
        $query = SalesOrderImportBatch::query()->latest();

        if ($state = $request->query('state')) {
            $query->where('state', $state);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 25))->appends($request->query());

        return $this->successResponse(
            collect($paginator->items())->map(fn ($b) => $this->batchPayload($b))->all(),
            'Daftar batch import pesanan',
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
        $model = SalesOrderImportBatch::find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse($this->batchPayload($model), 'Detail batch import pesanan');
    }

    public function errors(Request $request, string $batch)
    {
        $model = SalesOrderImportBatch::find($batch);
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
            'Daftar error batch import pesanan',
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
        $model = SalesOrderImportBatch::find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return Excel::download(
            new SalesOrderImportErrorReportExport($model),
            "import-errors-{$model->batch_no}.xlsx"
        );
    }

    public function downloadTemplate()
    {
        return Excel::download(new SalesOrderImportTemplateExport(), 'Template_Import_Pesanan.xlsx');
    }

    private function batchPayload(SalesOrderImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
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
