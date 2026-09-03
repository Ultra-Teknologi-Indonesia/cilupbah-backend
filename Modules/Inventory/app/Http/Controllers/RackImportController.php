<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\WarehouseAccess;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Exports\RackImportErrorExport;
use Modules\Inventory\Http\Resources\RackImportBatchResource;
use Modules\Inventory\Http\Resources\RackImportRowResource;
use Modules\Inventory\Jobs\ConfirmRackImportJob;
use Modules\Inventory\Jobs\PreviewRackImportJob;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Repositories\RackImportBatchRepository;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Inventory\Services\RackImport\RackImportBatchService;

class RackImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RackImportBatchService $batchService,
        private RackImportBatchRepository $repository,
        private ImpexActivityService $activityService,
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

        PreviewRackImportJob::dispatch($batch->id);

        return $this->successResponse(
            new RackImportBatchResource($batch),
            'File diterima. Pratinjau sedang diproses di latar belakang.',
            202,
        );
    }

    public function batches()
    {
        $paginator = $this->repository->paginateBatches();
        $paginator->through(fn (RackImportBatch $b) => new RackImportBatchResource($b));

        return $this->successPaginatedResponse($paginator, 'Daftar batch import alokasi rak');
    }

    public function show(string $batch)
    {
        $model = $this->repository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        return $this->successResponse(new RackImportBatchResource($model), 'Detail batch import');
    }

    public function rows(string $batch)
    {
        $model = $this->repository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $paginator = $this->repository->paginateRows($model);
        $paginator->through(fn ($row) => new RackImportRowResource($row));

        return $this->successPaginatedResponse($paginator, 'Daftar baris import');
    }

    public function confirm(Request $request, string $batch)
    {
        $model = $this->repository->find($batch);
        if (! $model) {
            return $this->errorResponse('Batch tidak ditemukan', 404);
        }

        $allowedLocationIds = WarehouseAccess::allowedIds();
        if ($allowedLocationIds !== null && $model->rows()
            ->whereNotNull('location_id')
            ->whereNotIn('location_id', $allowedLocationIds)
            ->exists()) {
            return $this->errorResponse(
                'Batch berisi lokasi yang berada di luar kewenangan Anda.',
                403,
                null,
                'Aksi tidak dapat diproses',
            );
        }

        if ($model->state !== RackImportBatch::STATE_PREVIEWED) {
            return $this->errorResponse(
                'Batch belum siap diterapkan.',
                422,
                ['detail' => "Status saat ini: {$model->state}."],
                'Aksi tidak dapat diproses',
            );
        }

        if (! $this->batchService->startConfirm($model)) {
            return $this->errorResponse('Batch sedang atau sudah diproses.', 422, null, 'Aksi tidak dapat diproses');
        }

        $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Alokasi Rak',
            $request->user()?->id,
            null,
            'rack_import_batch',
            $model->id,
        );

        ConfirmRackImportJob::dispatch($model->id);

        return $this->successResponse(
            new RackImportBatchResource($model->fresh()),
            'Penerapan sedang diproses di latar belakang.',
            202,
        );
    }

    public function downloadErrors(string $batch)
    {
        $model = $this->repository->find($batch);
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
