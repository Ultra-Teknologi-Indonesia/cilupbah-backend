<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Throwable;

final class ArchiveBulkShippingLabelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly string $batchId)
    {
        $this->onConnection(config('queue.routing.label_archive.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.label_archive.queue', 'label-archive'));
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(): void
    {
        $lock = Cache::lock('bulk-label-archive:'.$this->batchId, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $batch = BulkShippingLabelBatch::find($this->batchId);
            if (! $batch || $batch->status !== BulkShippingLabelBatch::STATUS_READY) {
                return;
            }

            if ($batch->archive_status === BulkShippingLabelBatch::ARCHIVE_ARCHIVED) {
                return;
            }

            $spoolPath = (string) $batch->print_pdf_path;
            if ($spoolPath === '') {

                $batch->update([
                    'archive_status' => BulkShippingLabelBatch::ARCHIVE_ARCHIVED,
                    'archived_at' => $batch->archived_at ?? now(),
                    'archive_error' => null,
                ]);

                return;
            }

            $spool = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
            $archive = Storage::disk(config('bulk-labels.archive_disk', 'documents'));
            if (! $spool->exists($spoolPath)) {
                throw new \RuntimeException('File print spool tidak ditemukan.');
            }

            $batch->update([
                'archive_status' => BulkShippingLabelBatch::ARCHIVE_PROCESSING,
                'archive_error' => null,
            ]);

            $archivePath = $batch->merged_pdf_path ?: 'bulk-labels/'.$batch->id.'.pdf';
            $stream = $spool->readStream($spoolPath);
            if (! is_resource($stream)) {
                throw new \RuntimeException('File print spool tidak dapat dibaca.');
            }

            try {
                if (! $archive->writeStream($archivePath, $stream)) {
                    throw new \RuntimeException('Upload arsip label ke object storage gagal.');
                }
            } finally {
                fclose($stream);
            }

            $localBytes = (int) $spool->size($spoolPath);
            $remoteBytes = (int) $archive->size($archivePath);
            if ($localBytes <= 0 || $localBytes !== $remoteBytes) {
                throw new \RuntimeException('Verifikasi ukuran arsip label tidak cocok.');
            }

            $checksum = null;
            $localAbsolutePath = method_exists($spool, 'path') ? $spool->path($spoolPath) : null;
            if (is_string($localAbsolutePath) && is_file($localAbsolutePath)) {
                $checksum = hash_file('sha256', $localAbsolutePath) ?: null;
            }

            $batch->update([
                'merged_pdf_path' => $archivePath,
                'merged_pdf_bytes' => $localBytes,
                'archive_status' => BulkShippingLabelBatch::ARCHIVE_ARCHIVED,
                'archive_checksum' => $checksum,
                'archive_pdf_bytes' => $remoteBytes,
                'archive_error' => null,
                'archived_at' => now(),
            ]);

            if (! $spool->delete($spoolPath)) {
                Log::warning('Bulk label spool cleanup failed after archive', [
                    'batch_id' => $this->batchId,
                    'path' => $spoolPath,
                ]);
            }
        } catch (Throwable $e) {
            BulkShippingLabelBatch::query()
                ->whereKey($this->batchId)
                ->where('archive_status', '!=', BulkShippingLabelBatch::ARCHIVE_ARCHIVED)
                ->update([
                    'archive_status' => BulkShippingLabelBatch::ARCHIVE_FAILED,
                    'archive_error' => mb_substr($e->getMessage(), 0, 1000),
                ]);

            Log::error('ArchiveBulkShippingLabelJob failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        BulkShippingLabelBatch::query()
            ->whereKey($this->batchId)
            ->where('archive_status', '!=', BulkShippingLabelBatch::ARCHIVE_ARCHIVED)
            ->update([
                'archive_status' => BulkShippingLabelBatch::ARCHIVE_FAILED,
                'archive_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
    }
}
