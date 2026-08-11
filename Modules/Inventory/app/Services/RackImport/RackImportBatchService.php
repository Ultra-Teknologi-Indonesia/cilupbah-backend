<?php

namespace Modules\Inventory\Services\RackImport;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;

class RackImportBatchService
{
    public const DISK = 's3';
    public const DIR = 'imports/rack-allocation';

    public function createFromUpload(UploadedFile $file, ?string $userId): RackImportBatch
    {
        $path = $file->store(self::DIR, self::DISK);

        return RackImportBatch::create([
            'batch_no' => $this->generateBatchNo(),
            'executed_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'state' => RackImportBatch::STATE_QUEUED,
        ]);
    }

    public function markPreviewing(RackImportBatch $batch): void
    {
        $batch->update(['state' => RackImportBatch::STATE_PREVIEWING]);
    }

    public function recordRows(RackImportBatch $batch, array $records): void
    {
        foreach (array_chunk($records, 500) as $chunk) {
            $rows = array_map(fn ($r) => [
                'id' => (string) Str::uuid(),
                'batch_id' => $batch->id,
                'row_no' => $r['row_no'],
                'raw_sku' => $this->clip($r['raw_sku'] ?? null),
                'raw_location' => $this->clip($r['raw_location'] ?? null),
                'raw_bin' => $this->clip($r['raw_bin'] ?? null),
                'item_id' => $r['item_id'] ?? null,
                'location_id' => $r['location_id'] ?? null,
                'bin_id' => $r['bin_id'] ?? null,
                'status' => $r['status'],
                'message' => $this->clip($r['message'] ?? null, 500),
                'product_name' => $this->clip($r['product_name'] ?? null),
                'current_bin' => $this->clip($r['current_bin'] ?? null),
                'created_at' => now(),
            ], $chunk);

            RackImportRow::insert($rows);
        }
    }

    public function finalizePreview(RackImportBatch $batch, int $totalRows, array $counts): void
    {
        $batch->update([
            'total_rows' => $totalRows,
            'place_rows' => $counts[RackImportBatch::STATUS_PLACE] ?? 0,
            'manual_move_rows' => $counts[RackImportBatch::STATUS_MANUAL_MOVE] ?? 0,
            'already_rows' => $counts[RackImportBatch::STATUS_ALREADY] ?? 0,
            'error_rows' => $counts[RackImportBatch::STATUS_ERROR] ?? 0,
            'state' => RackImportBatch::STATE_PREVIEWED,
        ]);
    }

    public function markPreviewFailed(RackImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => RackImportBatch::STATE_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
        ]);
    }

    public function startConfirm(RackImportBatch $batch): bool
    {
        $affected = RackImportBatch::whereKey($batch->id)
            ->where('state', RackImportBatch::STATE_PREVIEWED)
            ->update([
                'state' => RackImportBatch::STATE_CONFIRMING,
                'processed_rows' => 0,
                'success_rows' => 0,
                'failed_rows' => 0,
                'progress_percent' => 0,
            ]);

        if ($affected > 0) {
            $batch->refresh();

            return true;
        }

        return false;
    }

    public function incrementConfirm(RackImportBatch $batch, int $success, int $failed): void
    {
        DB::transaction(function () use ($batch, $success, $failed) {
            $fresh = RackImportBatch::whereKey($batch->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }

            $processed = $fresh->processed_rows + $success + $failed;
            $target = max($fresh->place_rows, 1);
            $percent = (int) min(100, floor($processed / $target * 100));

            $fresh->update([
                'processed_rows' => $processed,
                'success_rows' => $fresh->success_rows + $success,
                'failed_rows' => $fresh->failed_rows + $failed,
                'progress_percent' => $percent,
            ]);
        });
    }

    public function finalizeConfirm(RackImportBatch $batch): RackImportBatch
    {
        $fresh = $batch->fresh();

        $state = $fresh->success_rows >= $fresh->place_rows
            ? RackImportBatch::STATE_DONE
            : ($fresh->success_rows > 0 ? RackImportBatch::STATE_DONE_WITH_ERRORS : RackImportBatch::STATE_FAILED);

        $fresh->update([
            'state' => $state,
            'progress_percent' => 100,
        ]);

        return $fresh;
    }

    public function markConfirmFailed(RackImportBatch $batch, string $message): void
    {
        $batch->update([
            'state' => RackImportBatch::STATE_FAILED,
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
        return 'RAK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
