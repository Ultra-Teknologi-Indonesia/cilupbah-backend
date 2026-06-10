<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Repositories\ChannelProductListingRepository;

class ChannelProductListingService
{
    public function __construct(private ChannelProductListingRepository $repository) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): ProductChannelMapping
    {
        return $this->repository->find($id);
    }
}
