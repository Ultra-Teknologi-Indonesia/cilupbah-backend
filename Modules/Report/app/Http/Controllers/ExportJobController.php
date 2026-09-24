<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Http\Requests\ListExportJobsRequest;
use Modules\Report\Http\Resources\ExportJobResource;
use Modules\Report\Services\ExportJobService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportJobController extends Controller
{
    public function __construct(
        protected ExportJobService $service,
    ) {}

    public function index(ListExportJobsRequest $request): JsonResponse
    {
        $paginator = $this->service->paginate(
            $request->user(),
            min(200, max(20, $request->integer('per_page', 20))),
        );

        return $this->successPaginatedResponse(
            ExportJobResource::collection($paginator),
            'Riwayat download report berhasil diambil.',
        );
    }

    public function show(Request $request, string $export): JsonResponse
    {
        $job = $this->service->findOwnedOrFail($request->user(), $export);

        return $this->successResponse(ExportJobResource::make($job)->resolve($request));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $job = $this->service->findOwnedOrFail($request->user(), $export);
        abort_if($job->file_purged_at !== null || ! $job->file_path, 410, 'File hasil export sudah kedaluwarsa. Silakan buat export baru.');
        abort_unless($job->isReady(), 404);

        $disk = Storage::disk($job->file_disk ?? config('exports.disk', 's3'));
        abort_unless($disk->exists($job->file_path), 404);

        logger()->info('export.downloaded', [
            'export_id' => $job->id,
            'user_id' => $request->user()->id,
            'file_name' => $job->file_name,
        ]);

        return $disk->download($job->file_path, $job->file_name ?? 'export.xlsx');
    }
}
