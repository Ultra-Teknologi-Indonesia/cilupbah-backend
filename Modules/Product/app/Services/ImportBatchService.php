<?php

namespace Modules\Product\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Models\ProductImportError;

class ImportBatchService
{
    public const DISK = 'local';
    public const DIR = 'imports/products';

    public function createFromUpload(UploadedFile $file, string $type, ?string $userId): ProductImportBatch
    {
        $path = $file->store(self::DIR, self::DISK);

        return ProductImportBatch::create([
            'batch_no' => $this->generateBatchNo(),
            'type' => $type,
            'executed_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'state' => ProductImportBatch::STATE_QUEUED,
        ]);
    }

    public function markProcessing(ProductImportBatch $batch): void
    {
        $batch->update(['state' => ProductImportBatch::STATE_PROCESSING]);
    }

    public function recordErrors(ProductImportBatch $batch, array $errors): void
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

            ProductImportError::insert($rows);
        }
    }

    public function finalize(ProductImportBatch $batch, int $total, int $success, int $failed): void
    {
        $state = $failed > 0
            ? ($success > 0 ? ProductImportBatch::STATE_DONE_WITH_ERRORS : ProductImportBatch::STATE_FAILED)
            : ProductImportBatch::STATE_DONE;

        $batch->update([
            'total_rows' => $total,
            'processed_rows' => $success + $failed,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'progress_percent' => 100,
            'state' => $state,
        ]);
    }

    public function markFailed(ProductImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => ProductImportBatch::STATE_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
            'progress_percent' => 100,
        ]);
    }

    private function generateBatchNo(): string
    {
        return 'IMP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
