<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\MasterFeedRepository;

class MasterFeedService
{
    public function __construct(private MasterFeedRepository $repository) {}

    public function paginate(?string $status = null, ?string $updatedSince = null): LengthAwarePaginator
    {
        return $this->repository->paginate($status ?? Product::STATUS_MASTER, $updatedSince);
    }

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return $this->repository->paginateDownloaded($updatedSince);
    }

    public function find(string $id): Product
    {
        return $this->repository->find($id, Product::STATUS_MASTER);
    }
}
