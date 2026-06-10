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

    /** Jubelio: GET /inventory/internal-price-list. */
    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    /** Jubelio: POST /inventory/price-list. */
    public function updatePrices(array $items): void
    {
        $this->repository->updatePrices($items);
    }
}
