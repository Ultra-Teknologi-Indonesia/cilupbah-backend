<?php

namespace Modules\Tax\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Tax\Models\Tax;
use Modules\Tax\Repositories\TaxRepository;

class TaxService
{
    public function __construct(
        private readonly TaxRepository $repository,
    ) {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(int $id): ?Tax
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): Tax
    {
        return $this->repository->create($data);
    }

    public function update(Tax $tax, array $data): Tax
    {
        return $this->repository->update($tax, $data);
    }

    public function delete(Tax $tax): void
    {
        $this->repository->delete($tax);
    }
}
