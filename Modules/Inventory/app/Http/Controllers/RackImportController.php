<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Exports\RackImportErrorExport;
use Modules\Inventory\Http\Resources\RackImportBatchResource;
use Modules\Inventory\Http\Resources\RackImportRowResource;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Services\RackImport\RackImportBatchService;

class RackImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RackImportBatchService $batchService,
    ) {}

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:20480',
        ]);

        $batch = $this->batchService->createFromUpload(
            $request->file('file'),
            $request->user()?->id,
        );

        $this->batchService->queuePreview($batch);

        return $this->successResponse(
            new RackImportBatchResource($batch),
            'File diterima. Pratinjau sedang diproses di latar belakang.',
            202,
        );
    }

    public function batches()
    {
        $paginator = $this->batchService->paginateBatches();
        $paginator->through(fn (RackImportBatch $b) => new RackImportBatchResource($b));

        return $this->successPaginatedResponse($paginator, 'Daftar batch import alokasi rak');
    }

    public function show(string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse(new RackImportBatchResource($model), 'Detail batch import');
    }

    public function rows(string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->batchService->paginateRows($model);
        $paginator->through(fn ($row) => new RackImportRowResource($row));

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
            new RackImportBatchResource($model->fresh()),
            'Penerapan sedang diproses di latar belakang.',
            202,
        );
    }

    public function downloadErrors(string $batch)
    {
        $model = $this->batchService->findBatch($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $content = Excel::raw(new RackImportErrorExport($model), \Maatwebsite\Excel\Excel::XLSX);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"rack-import-errors-{$model->batch_no}.xlsx\"",
            'Content-Length' => \strlen($content),
        ]);
    }
}
