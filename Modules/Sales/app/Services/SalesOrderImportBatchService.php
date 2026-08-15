<?php

namespace Modules\Sales\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrderImportBatch;
use Modules\Sales\Models\SalesOrderImportError;

class SalesOrderImportBatchService
{
    public const DISK = 's3';
    public const DIR = 'imports/sales-orders';

    public static function disk(): string
    {
        return (string) env('IMPORT_FILESYSTEM_DISK', config('filesystems.default', self::DISK));
    }

    public function createFromUpload(UploadedFile $file, ?string $userId): SalesOrderImportBatch
    {
        $disk = self::disk();
        if ($disk === 'local') {
            $targetDir = Storage::disk($disk)->path(self::DIR);
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
        }

        $path = $file->store(self::DIR, $disk);

        return SalesOrderImportBatch::create([
            'batch_no' => $this->generateBatchNo(),
            'executed_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'state' => SalesOrderImportBatch::STATE_QUEUED,
        ]);
    }

    public function markProcessing(SalesOrderImportBatch $batch): void
    {
        $batch->update(['state' => SalesOrderImportBatch::STATE_PROCESSING]);
    }

    public function recordErrors(SalesOrderImportBatch $batch, array $errors): void
    {
        foreach (array_chunk($errors, 500) as $chunk) {
            $rows = array_map(fn ($e) => [
                'id' => (string) Str::uuid(),
                'import_batch_id' => $batch->id,
                'row_number' => $e['row_number'],
                'attribute' => $e['attribute'] ?? null,
                'message' => mb_substr($e['message'], 0, 1000),
                'row_snapshot' => isset($e['row_snapshot']) ? json_encode($e['row_snapshot']) : null,
                'created_at' => now(),
            ], $chunk);

            SalesOrderImportError::insert($rows);
        }
    }

    public function finalize(SalesOrderImportBatch $batch, int $total, int $success, int $failed): void
    {
        $state = $failed > 0
            ? ($success > 0 ? SalesOrderImportBatch::STATE_DONE_WITH_ERRORS : SalesOrderImportBatch::STATE_FAILED)
            : SalesOrderImportBatch::STATE_DONE;

        $batch->update([
            'total_rows' => $total,
            'processed_rows' => $success + $failed,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'progress_percent' => 100,
            'state' => $state,
        ]);
    }

    public function markFailed(SalesOrderImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => SalesOrderImportBatch::STATE_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
            'progress_percent' => 100,
        ]);
    }

    private function generateBatchNo(): string
    {
        return 'IMP-SO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
