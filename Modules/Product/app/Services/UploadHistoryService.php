<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Repositories\UploadHistoryRepository;

class UploadHistoryService
{
    public function __construct(private UploadHistoryRepository $repository) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function paginateErrors(): LengthAwarePaginator
    {
        return $this->repository->paginateErrors();
    }

    public function reupload(string $logId): ProductSyncLog
    {
        $log = $this->repository->findUpload($logId);

        if ($log->status === ProductSyncLog::STATUS_SUCCESS) {
            throw new DomainException('Entri yang sukses tidak dapat di-upload ulang.');
        }

        if (! $log->product_id || ! $log->channel_shop_id) {
            throw new DomainException('Produk atau toko untuk entri ini tidak tersedia.');
        }

        SyncProductToChannelJob::dispatch($log->product_id, $log->channel_shop_id, 'push');

        $log->update([
            'status' => ProductSyncLog::STATUS_PENDING,
            'error_message' => null,
        ]);

        return $log;
    }

    public function delete(string $logId): void
    {
        $this->repository->findUpload($logId)->delete();
    }

    public function bulkDelete(array $ids): int
    {
        return $this->repository->deleteMany($ids);
    }

    public function pruneExpired(int $days = 30): int
    {
        return $this->repository->pruneSuccessOlderThan($days);
    }
}
