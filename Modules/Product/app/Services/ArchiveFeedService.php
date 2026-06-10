<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ArchiveFeedRepository;

class ArchiveFeedService
{
    public function __construct(private ArchiveFeedRepository $repository) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): Product
    {
        return $this->repository->find($id);
    }
}
