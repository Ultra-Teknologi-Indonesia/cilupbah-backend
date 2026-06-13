<?php

namespace Modules\Product\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Repositories\PriceListRepository;

class PriceListService
{
    public function __construct(
        private readonly PriceListRepository $repository,
    ) {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function updatePrices(array $items): void
    {
        $this->repository->updatePrices($items);
    }
}
