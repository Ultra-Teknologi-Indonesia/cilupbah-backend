<?php

namespace Modules\Warranty\Services;

use Modules\Warranty\Repositories\WarrantyRepository;
use Modules\Warranty\Models\Warranty;
use Illuminate\Pagination\LengthAwarePaginator;

class WarrantyService
{
    protected WarrantyRepository $repository;

    public function __construct(WarrantyRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAllPaginated(int $limit = 10): LengthAwarePaginator
    {
        return $this->repository->getPaginated($limit);
    }

    public function getById(string $id): ?Warranty
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): Warranty
    {
        $data['id'] = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $data['status'] = 'active';

        return $this->repository->create($data);
    }

    public function update(string $id, array $data): Warranty
    {
        $this->repository->update($id, $data);
        return $this->repository->findById($id);
    }

    public function delete(string $id): bool
    {
        return $this->repository->delete($id);
    }
}
