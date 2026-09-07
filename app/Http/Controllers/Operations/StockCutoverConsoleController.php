<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Inventory\Jobs\RunStockCutoverConsoleJob;
use Modules\Inventory\Models\StockCutoverConsoleJob;

final class StockCutoverConsoleController extends Controller
{
    private const LOCATIONS = [
        'O' => 'gudang_kecil',
        'WH-PUSAT' => 'pusat',
    ];

    public function index(string $token, Request $request): View
    {
        $selected = $request->query('job');
        $job = $selected ? StockCutoverConsoleJob::find($selected) : null;

        return view('operations.stock-cutover-console', compact('token', 'job'));
    }

    public function preview(string $token, Request $request): RedirectResponse
    {
        $max = (int) config('operations.stock_cutover_console.max_upload_kilobytes', 10240);
        $validated = $request->validate([
            'gudang_kecil' => ['required', 'file', 'mimes:xlsx', "max:{$max}"],
            'pusat' => ['required', 'file', 'mimes:xlsx', "max:{$max}"],
        ]);

        $id = (string) Str::uuid7();
        $diskName = (string) config('operations.stock_cutover_console.upload_disk', 'local');
        $disk = Storage::disk($diskName);
        $files = [];

        foreach (self::LOCATIONS as $locationCode => $field) {
            $upload = $validated[$field];
            $path = "stock-cutover-console/{$id}/{$field}.xlsx";
            $disk->putFileAs(dirname($path), $upload, basename($path));

            $files[$locationCode] = [
                'disk' => $diskName,
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'sha256' => hash_file('sha256', $upload->getRealPath()),
            ];
        }

        $job = StockCutoverConsoleJob::create([
            'id' => $id,
            'type' => 'preview',
            'status' => StockCutoverConsoleJob::STATUS_QUEUED,
            'files' => $files,
        ]);

        RunStockCutoverConsoleJob::dispatch($job->id);

        return redirect()->route('operations.stock-cutover.index', ['token' => $token, 'job' => $job->id]);
    }

    public function status(string $token, StockCutoverConsoleJob $job): JsonResponse
    {
        return response()->json([
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'report' => $job->report,
            'error' => $job->error,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'download_ready' => $job->report_path !== null,
        ]);
    }

    public function apply(string $token, Request $request, StockCutoverConsoleJob $job): RedirectResponse
    {
        $validated = $request->validate([
            'operations_stopped' => ['accepted'],
            'confirmation' => ['required', 'in:APPLY-STOK-AKTUAL'],
        ]);
        unset($validated);

        if ($job->type !== 'preview' || $job->status !== StockCutoverConsoleJob::STATUS_READY) {
            return back()->withErrors(['apply' => 'Preview harus selesai terlebih dahulu.']);
        }

        if ((bool) data_get($job->report, 'blocking', true)) {
            return back()->withErrors(['apply' => 'Preview masih memiliki baris bermasalah. Download laporan dan perbaiki file sebelum apply.']);
        }

        $apply = StockCutoverConsoleJob::create([
            'type' => 'apply',
            'status' => StockCutoverConsoleJob::STATUS_QUEUED,
            'source_job_id' => $job->id,
            'files' => $job->files,
        ]);

        RunStockCutoverConsoleJob::dispatch($apply->id);

        return redirect()->route('operations.stock-cutover.index', ['token' => $token, 'job' => $apply->id]);
    }

    public function download(string $token, StockCutoverConsoleJob $job)
    {
        if ($job->report_path === null || $job->report_disk === null) {
            abort(404);
        }

        return Storage::disk($job->report_disk)->download(
            $job->report_path,
            "stock-cutover-{$job->id}-report.json",
            ['Content-Type' => 'application/json']
        );
    }

    public function downloadCsv(string $token, StockCutoverConsoleJob $job, string $location)
    {
        if (! array_key_exists($location, self::LOCATIONS) || $job->report_disk === null) {
            abort(404);
        }

        $path = "stock-cutover-console/{$job->id}/{$location}-report.csv";
        $disk = Storage::disk($job->report_disk);
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download(
            $path,
            "stock-cutover-{$job->id}-{$location}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
