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
use Modules\Inventory\Jobs\RunOrderCutoverConsoleJob;
use Modules\Inventory\Models\OrderCutoverConsoleJob;

final class OrderCutoverConsoleController extends Controller
{
    private const FILE_FIELDS = [
        'failed_pickup' => 'Gagal pengambilan / pick gagal',
        'no_internal_stock' => 'Stok kosong di internal',
        'ready_to_process' => 'Siap proses',
        'awaiting_payment' => 'Menunggu pembayaran',
    ];

    public function index(string $token, Request $request): View
    {
        $selected = $request->query('job');
        $job = $selected ? OrderCutoverConsoleJob::find($selected) : null;

        return view('operations.order-cutover-console', [
            'token' => $token,
            'job' => $job,
            'fileFields' => self::FILE_FIELDS,
            'defaultLocation' => config('operations.order_cutover_console.default_location', 'O'),
        ]);
    }

    public function preview(string $token, Request $request): RedirectResponse
    {
        $max = (int) config('operations.order_cutover_console.max_upload_kilobytes', 10240);
        $rules = [
            'cutoff' => ['required', 'date_format:Y-m-d\\TH:i'],
            'locations' => ['required', 'string', 'regex:/^[A-Za-z0-9,_-]+$/'],
        ];
        foreach (array_keys(self::FILE_FIELDS) as $field) {
            $rules[$field] = ['required', 'file', 'mimes:csv,txt', "max:{$max}"];
        }
        $validated = $request->validate($rules);
        $id = (string) Str::uuid7();
        $diskName = (string) config('operations.order_cutover_console.upload_disk', 's3');
        $disk = Storage::disk($diskName);
        $files = [];
        foreach (self::FILE_FIELDS as $field => $label) {
            $upload = $validated[$field];
            $path = "order-cutover-console/{$id}/{$field}.csv";
            $disk->putFileAs(dirname($path), $upload, basename($path));
            $files[] = [
                'disk' => $diskName,
                'path' => $path,
                'category' => $field,
                'label' => $label,
                'original_name' => $upload->getClientOriginalName(),
                'sha256' => hash_file('sha256', $upload->getRealPath()),
            ];
        }
        $locations = collect(explode(',', (string) $validated['locations']))
            ->map(fn ($value): string => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $job = OrderCutoverConsoleJob::create([
            'id' => $id,
            'type' => 'preview',
            'status' => OrderCutoverConsoleJob::STATUS_QUEUED,
            'files' => $files,
            'location_codes' => $locations,
            'cutoff_at' => \Carbon\CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $validated['cutoff'], 'Asia/Jakarta')->utc(),
        ]);
        RunOrderCutoverConsoleJob::dispatch($job->id);

        return redirect()->route('operations.order-cutover.index', ['token' => $token, 'job' => $job->id]);
    }

    public function status(string $token, OrderCutoverConsoleJob $job): JsonResponse
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

    public function apply(string $token, Request $request, OrderCutoverConsoleJob $job): RedirectResponse
    {
        $request->validate([
            'warehouse_stopped' => ['accepted'],
            'order_sync_paused' => ['accepted'],
            'confirmation' => ['required', 'in:APPLY-ORDER-CUTOVER'],
        ]);
        if ($job->type !== 'preview' || $job->status !== OrderCutoverConsoleJob::STATUS_READY) {
            return back()->withErrors(['apply' => 'Preview order cutover harus selesai terlebih dahulu.']);
        }
        if ((int) data_get($job->report, 'blocking', 1) > 0) {
            return back()->withErrors(['apply' => 'Preview masih memiliki blocking issue. Perbaiki data dan jalankan job baru.']);
        }
        $apply = OrderCutoverConsoleJob::create([
            'type' => 'apply',
            'status' => OrderCutoverConsoleJob::STATUS_QUEUED,
            'files' => $job->files,
            'location_codes' => $job->location_codes,
            'cutoff_at' => $job->cutoff_at,
        ]);
        RunOrderCutoverConsoleJob::dispatch($apply->id);

        return redirect()->route('operations.order-cutover.index', ['token' => $token, 'job' => $apply->id]);
    }

    public function download(string $token, OrderCutoverConsoleJob $job)
    {
        if ($job->report_path === null || $job->report_disk === null) {
            abort(404);
        }

        return Storage::disk($job->report_disk)->download(
            $job->report_path,
            "order-cutover-{$job->id}-report.json",
            ['Content-Type' => 'application/json'],
        );
    }
}
