<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Jobs\FinalizeBulkShippingLabelBatchJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;
use Throwable;

final class BulkShippingLabelSnapshotService
{
    public function __construct(private BulkShippingLabelRequestRepository $repository) {}

    public function create(User $user, BulkShippingLabelBatch $source): BulkShippingLabelBatch
    {
        abort_unless((string) $source->user_id === (string) $user->id, 403);

        $lock = Cache::lock("bulk-label-finalize:{$source->id}", (int) config('bulk-labels.finalize_lock_seconds', 900));
        abort_unless($lock->get(), 409, 'Label sedang digabungkan. Coba cetak kembali sebentar lagi.');
        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        $copied = [];
        $copyLock = null;

        try {
            $source->refresh();
            $limit = max(1, min(500, (int) config('bulk-labels.snapshot_max_items', 500)));
            $warehouseIds = $user->allowedLocationIds();
            $items = $this->repository->printableItems((string) $source->id, $warehouseIds, $limit + 1);
            abort_if($items->isEmpty(), 409, 'Belum ada label yang siap dicetak.');
            abort_if($items->count() > $limit, 422, "Maksimal {$limit} label siap per cetak sebagian. Tunggu hasil gabungan atau pilih batch lebih kecil.");

            if ($source->status === BulkShippingLabelBatch::STATUS_READY) {
                abort_unless($items->count() === $source->items()->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)->count(), 409, 'Akses atau status pesanan berubah. Pilih ulang pesanan yang dapat dicetak.');
                abort_if($source->file_purged_at !== null, 410, 'File label sudah kedaluwarsa.');

                return $source;
            }

            $key = hash('sha256', json_encode(['ready-snapshot', $source->id, $items->modelKeys(), $warehouseIds], JSON_THROW_ON_ERROR));
            $snapshot = $this->repository->reusableSnapshot((string) $user->id, $key);
            if ($snapshot === null) {
                $copyLock = Cache::lock('bulk-label-snapshot-copy', 120);
                abort_unless($copyLock->get(), 409, 'Penyiapan cetak lain sedang berlangsung. Coba lagi sebentar.');
                $totalBytes = 0;
                foreach ($items as $item) {
                    abort_unless($item->ready_pdf_path && $disk->exists($item->ready_pdf_path), 409, 'File label belum tersedia. Muat ulang lalu coba lagi.');
                    $totalBytes += $disk->size($item->ready_pdf_path);
                }
                $maxBytes = min(64 * 1024 * 1024, max(1, (int) config('bulk-labels.snapshot_max_bytes', 64 * 1024 * 1024)));
                abort_if($totalBytes > $maxBytes, 422, 'Ukuran label siap terlalu besar. Tunggu hasil gabungan atau pilih batch lebih kecil.');
                $available = @disk_free_space($disk->path(''));
                abort_if($available === false || $available < $totalBytes * 2 + 256 * 1024 * 1024, 503, 'Ruang penyimpanan cetak sementara sedang terbatas. Coba kembali setelah antrean cetak selesai.');

                $snapshot = DB::transaction(function () use ($user, $source, $key, $items, $disk, &$copied): BulkShippingLabelBatch {
                    $snapshot = BulkShippingLabelBatch::create([
                        'user_id' => $user->id, 'request_key' => $key,
                        'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
                        'per_channel_opts' => $source->per_channel_opts,
                        'total_count' => $items->count(), 'done_count' => $items->count(),
                        'failed_count' => 0, 'skipped_count' => 0, 'started_at' => now(),
                    ]);
                    foreach ($items as $item) {
                        $id = (string) Str::uuid7();
                        $path = "items/{$snapshot->id}/{$id}.pdf";
                        $copied[] = $path;

                        if (! $disk->copy($item->ready_pdf_path, $path)) {
                            throw new \RuntimeException('Gagal menyiapkan salinan label. Silakan coba kembali.');
                        }
                        BulkShippingLabelItem::create([
                            'batch_id' => $snapshot->id, 'order_id' => $item->order_id,
                            'channel' => $item->channel, 'status' => BulkShippingLabelItem::STATUS_READY,
                            'ready_pdf_path' => $path,
                        ]);
                    }

                    return $snapshot;
                });
            }
        } catch (Throwable $exception) {
            foreach ($copied as $path) {
                $disk->delete($path);
            }
            throw $exception;
        } finally {
            if ($copyLock?->isOwnedByCurrentProcess()) {
                $copyLock->release();
            }
            $lock->release();
        }

        if ($snapshot->status === BulkShippingLabelBatch::STATUS_PROCESSING) {
            FinalizeBulkShippingLabelBatchJob::dispatch((string) $snapshot->id)->afterCommit();
        }

        return $snapshot;
    }
}
