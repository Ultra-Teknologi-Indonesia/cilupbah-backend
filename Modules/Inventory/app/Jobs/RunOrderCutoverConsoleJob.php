<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\OrderCutoverConsoleJob;
use Modules\Inventory\Services\OrderCutoverService;
use Throwable;

final class RunOrderCutoverConsoleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $consoleJobId)
    {
        $this->onConnection((string) config('operations.order_cutover_console.queue_connection', 'redis-long'));
        $this->onQueue((string) config('operations.order_cutover_console.queue', 'order-cutover'));
    }

    public function handle(OrderCutoverService $service): void
    {
        $job = OrderCutoverConsoleJob::find($this->consoleJobId);
        if (! $job || $job->status === OrderCutoverConsoleJob::STATUS_READY) {
            return;
        }

        ini_set('memory_limit', '1024M');
        set_time_limit($this->timeout);
        $job->update([
            'status' => OrderCutoverConsoleJob::STATUS_PROCESSING,
            'started_at' => $job->started_at ?? now(),
            'error' => null,
        ]);

        $workingDirectory = "order-cutover-console-tmp/{$job->id}";
        try {
            $localDisk = Storage::disk('local');
            $localDisk->makeDirectory($workingDirectory);
            $paths = [];
            $meta = [];
            foreach (($job->files ?? []) as $file) {
                $path = $this->materializeSourceFile($file, $workingDirectory);
                $paths[] = $path;
                $meta[$path] = $file;
            }

            $cutoff = CarbonImmutable::parse($job->cutoff_at)->utc();
            $locationCodes = array_values(array_map('strval', $job->location_codes ?? []));
            $isHardCutoff = in_array($job->type, ['hard_preview', 'hard_apply', 'hard_apply_partial'], true);
            $isApply = in_array($job->type, ['apply', 'apply_partial', 'hard_apply', 'hard_apply_partial'], true);
            $allowPartial = in_array($job->type, ['apply_partial', 'hard_apply_partial'], true);
            $report = match (true) {
                $isHardCutoff && $isApply => $service->applyHardCutoff($cutoff, $locationCodes, $allowPartial),
                $isHardCutoff => $service->previewHardCutoff($cutoff, $locationCodes),
                $isApply => $service->apply($paths, $cutoff, $locationCodes, $meta, $allowPartial),
                default => $service->preview($paths, $cutoff, $locationCodes, $meta),
            };

            $reportDisk = (string) config(
                'operations.order_cutover_console.report_disk',
                config('operations.order_cutover_console.upload_disk', 's3'),
            );
            $reportPath = "order-cutover-console/{$job->id}/report.json";
            Storage::disk($reportDisk)->put($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $job->update([
                'status' => OrderCutoverConsoleJob::STATUS_READY,
                'report' => $report,
                'report_disk' => $reportDisk,
                'report_path' => $reportPath,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => OrderCutoverConsoleJob::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($workingDirectory);
        }
    }

    private function materializeSourceFile(array $file, string $workingDirectory): string
    {
        $diskName = (string) ($file['disk'] ?? '');
        $sourcePath = (string) ($file['path'] ?? '');
        if ($diskName === '' || $sourcePath === '') {
            throw new \RuntimeException('file sumber order cutover tidak lengkap.');
        }
        $sourceDisk = Storage::disk($diskName);
        if (! $sourceDisk->exists($sourcePath)) {
            throw new \RuntimeException('file sumber order cutover tidak ditemukan.');
        }
        $localPath = Storage::disk('local')->path($workingDirectory.'/'.basename($sourcePath));
        $input = $sourceDisk->readStream($sourcePath);
        $output = fopen($localPath, 'wb');
        if (! is_resource($input) || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new \RuntimeException('file sumber order cutover tidak dapat disiapkan.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('file sumber order cutover gagal disalin.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $expectedHash = (string) ($file['sha256'] ?? '');
        if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) hash_file('sha256', $localPath))) {
            throw new \RuntimeException('integritas file CSV order cutover tidak cocok; proses dibatalkan.');
        }

        return $localPath;
    }
}
