<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportJobController extends Controller
{
    public function show(Request $request, string $export): JsonResponse
    {
        $job = ExportJob::findOrFail($export);
        abort_unless($job->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => [
                'id' => $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'file_name' => $job->file_name,

                'error' => $job->isFailed()
                    ? 'Gagal membuat berkas export. Coba lagi atau persempit rentang data.'
                    : null,
                'download_url' => $job->isReady()
                    ? route('reports.exports.download', $job->id)
                    : null,
            ],
        ]);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $job = ExportJob::findOrFail($export);
        abort_unless($job->user_id === $request->user()->id, 403);
        abort_unless($job->isReady() && $job->file_path, 404);

        $disk = Storage::disk($job->file_disk ?? 'local');
        abort_unless($disk->exists($job->file_path), 404);

        return $disk->download($job->file_path, $job->file_name ?? 'export.xlsx');
    }
}
