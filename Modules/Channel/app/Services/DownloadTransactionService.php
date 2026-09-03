<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\DownloadTransactionRepository;
use Modules\Channel\Support\DownloadFailureReport;

final class DownloadTransactionService
{
    public function __construct(
        private readonly DownloadTransactionRepository $repository,
        private readonly DownloadFailureService $failureService,
    ) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): DownloadTransaction
    {
        return $this->repository->find($id);
    }

    public function paginateShopProducts(string $channelShopId): LengthAwarePaginator
    {
        return $this->repository->paginateShopProducts($channelShopId);
    }

    public function failures(DownloadTransaction $transaction): DownloadFailureReport
    {
        return $this->failureService->report($transaction);
    }
}
