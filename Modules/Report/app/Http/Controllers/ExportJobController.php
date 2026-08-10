<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Services\ExportManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportJobController extends Controller
{
    public function __construct(protected ExportManager $exports) {}

    public function show(Request $request, string $export): JsonResponse
    {
        $job = $this->exports->findOwnedOrFail($export, $request->user()->id);

        return $this->successResponse($this->exports->statusPayload($job));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $job = $this->exports->findOwnedOrFail($export, $request->user()->id);
        abort_unless($job->isReady() && $job->file_path, 404);

        $disk = Storage::disk($job->file_disk ?? 'local');
        abort_unless($disk->exists($job->file_path), 404);

        return $disk->download($job->file_path, $job->file_name ?? 'export.xlsx');
    }
}
