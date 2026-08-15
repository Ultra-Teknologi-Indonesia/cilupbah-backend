<?php

namespace Modules\Product\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Models\ProductImportError;

class ImportBatchService
{
    public const DISK = 's3';
    public const DIR = 'imports/products';

    public static function disk(): string
    {
        return (string) env('IMPORT_FILESYSTEM_DISK', config('filesystems.default', self::DISK));
    }

    public function createFromUpload(UploadedFile $file, string $type, ?string $userId): ProductImportBatch
    {
        $disk = self::disk();
        if ($disk === 'local') {
            $targetDir = Storage::disk($disk)->path(self::DIR);
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
        }

        $path = $file->store(self::DIR, $disk);

        return ProductImportBatch::create([
            'batch_no' => $this->generateBatchNo(),
            'type' => $type,
            'executed_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'state' => ProductImportBatch::STATE_QUEUED,
        ]);
    }

    public function markPreviewing(ProductImportBatch $batch): void
    {
        $batch->update(['state' => ProductImportBatch::STATE_PREVIEWING]);
    }

    public function recordRows(ProductImportBatch $batch, array $records): void
    {
        foreach (array_chunk($records, 500) as $chunk) {
            $rows = array_map(fn ($r) => [
                'id' => (string) Str::uuid(),
                'import_batch_id' => $batch->id,
                'row_number' => $r['row_number'],
                'sku' => $this->clip($r['sku'] ?? null),
                'name' => $this->clip($r['name'] ?? null),
                'category_name' => $this->clip($r['category_name'] ?? null),
                'sell_price' => isset($r['sell_price']) ? (float) $r['sell_price'] : null,
                'status' => $r['status'] ?? 'valid',
                'message' => $this->clip($r['message'] ?? null, 1000),
                'payload' => isset($r['payload']) ? json_encode($r['payload']) : null,
                'created_at' => now(),
            ], $chunk);

            \Modules\Product\Models\ProductImportRow::insert($rows);
        }
    }

    public function finalizePreview(ProductImportBatch $batch, int $total, int $valid, int $invalid): void
    {
        $batch->update([
            'total_rows' => $total,
            'processed_rows' => $total,
            'success_rows' => $valid,
            'failed_rows' => $invalid,
            'progress_percent' => 100,
            'state' => ProductImportBatch::STATE_PREVIEWED,
        ]);
    }

    public function markPreviewFailed(ProductImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => ProductImportBatch::STATE_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
            'progress_percent' => 100,
        ]);
    }

    public function startConfirm(ProductImportBatch $batch): bool
    {
        $affected = ProductImportBatch::whereKey($batch->id)
            ->where('state', ProductImportBatch::STATE_PREVIEWED)
            ->update([
                'state' => ProductImportBatch::STATE_CONFIRMING,
                'processed_rows' => 0,
                'progress_percent' => 0,
            ]);

        if ($affected > 0) {
            $batch->refresh();

            return true;
        }

        return false;
    }

    public function incrementConfirm(ProductImportBatch $batch, int $success, int $failed): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($batch, $success, $failed) {
            /** @var ProductImportBatch|null $fresh */
            $fresh = ProductImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }

            $processed = (int) $fresh->processed_rows + $success + $failed;
            $target = max((int) $fresh->success_rows, 1);
            $percent = (int) min(100, floor($processed / $target * 100));

            $fresh->update([
                'processed_rows' => $processed,
                'progress_percent' => $percent,
            ]);
        });
    }

    public function finalizeConfirm(ProductImportBatch $batch, int $totalApplied, int $totalFailed): ProductImportBatch
    {
        /** @var ProductImportBatch $fresh */
        $fresh = $batch->fresh() ?? $batch;

        $state = $totalFailed === 0
            ? ProductImportBatch::STATE_DONE
            : ($totalApplied > 0 ? ProductImportBatch::STATE_DONE_WITH_ERRORS : ProductImportBatch::STATE_FAILED);

        $fresh->update([
            'state' => $state,
            'processed_rows' => $totalApplied + $totalFailed,
            'progress_percent' => 100,
        ]);

        return $fresh;
    }

    public function markConfirmFailed(ProductImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => ProductImportBatch::STATE_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
            'progress_percent' => 100,
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

    private function clip(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function generateBatchNo(): string
    {
        return 'IMP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
