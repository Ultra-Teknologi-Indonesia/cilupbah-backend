<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Models\Brand;
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

    public function getBrandById(int $id): ?Brand
    {
        $brand = $this->repository->findById($id);
        if (!$brand) {
            throw new Exception("Brand not found");
        }
        return $brand;
    }

    public function createBrand(array $data): Brand
    {
        return $this->repository->create($data);
    }

    public function updateBrand(int $id, array $data): Brand
    {
        $brand = $this->getBrandById($id);
        return $this->repository->update($brand, $data);
    }

    public function deleteBrand(int $id): bool
    {
        $brand = $this->getBrandById($id);

        if ($brand->products()->count() > 0) {
            throw new Exception("Cannot delete brand because it is used by products");
        }

        return $this->repository->delete($brand);
    }
}
