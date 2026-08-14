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
use Modules\Sales\Http\Resources\SalesOrderImportBatchResource;
use Modules\Sales\Http\Resources\SalesOrderImportErrorResource;
use Modules\Sales\Jobs\ProcessSalesOrderImportJob;
use Modules\Sales\Repositories\SalesOrderImportBatchRepository;
use Modules\Sales\Services\SalesOrderImportBatchService;

class SalesOrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SalesOrderImportBatchService $batchService,
        private SalesOrderImportBatchRepository $batchRepository,
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
            new SalesOrderImportBatchResource($batch),
            'File diterima. Import sedang diproses di latar belakang.',
            202
        );
    }

    public function batches(Request $request)
    {
        $paginator = $this->batchRepository->paginate(
            $request->query('state'),
            (int) $request->query('per_page', 25),
        );

        return $this->successResponse(
            SalesOrderImportBatchResource::collection($paginator->items()),
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
        $model = $this->batchRepository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse(
            new SalesOrderImportBatchResource($model),
            'Detail batch import pesanan'
        );
    }

    public function errors(Request $request, string $batch)
    {
        $model = $this->batchRepository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->batchRepository->paginateErrors(
            $model,
            (int) $request->query('per_page', 50),
        );

        return $this->successResponse(
            SalesOrderImportErrorResource::collection($paginator->items()),
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
        $model = $this->batchRepository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $content = Excel::raw(new SalesOrderImportErrorReportExport($model), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"import-errors-{$model->batch_no}.xlsx\"",
            'Content-Length' => \strlen($content),
        ]);
    }

    public function downloadTemplate()
    {
        $content = Excel::raw(new SalesOrderImportTemplateExport(), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template_Import_Pesanan.xlsx"',
            'Content-Length' => \strlen($content),
        ]);
    }
}
