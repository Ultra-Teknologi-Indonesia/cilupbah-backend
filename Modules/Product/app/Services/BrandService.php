<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Repositories\BrandRepository;

class BrandService
{
    protected BrandRepository $repository;

    public function __construct(BrandRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getPaginatedBrands(int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->getPaginated($perPage);
    }

    public function getAllBrands(): Collection
    {
        return $this->repository->getAll();
    }
}
