<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Repositories\ProductPantauanRepository;

class ProductPantauanService
{
    public function __construct(private ProductPantauanRepository $repository) {}

    public function paginate(string $lens): LengthAwarePaginator
    {
        return $this->repository->paginate($lens);
    }
}
