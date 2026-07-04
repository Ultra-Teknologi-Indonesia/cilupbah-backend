<?php

namespace Modules\Supplier\Services;

use Modules\Supplier\Repositories\SalesmanRepository;
use Modules\Supplier\Models\Salesman;
use Illuminate\Support\Str;

class SalesmanService
{
    public function __construct(
        protected SalesmanRepository $salesmanRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->salesmanRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Salesman
    {
        return $this->salesmanRepository->findById($id);
    }

    public function create(array $data): Salesman
    {
        if (empty($data['code'])) {
            $data['code'] = 'SLM-' . Str::upper(Str::random(6));
        }
        return $this->salesmanRepository->create($data);
    }

    public function update(string $id, array $data): Salesman
    {
        $salesman = $this->salesmanRepository->findById($id);
        if (! $salesman) {
            throw new \Exception('Salesman tidak ditemukan.');
        }
        return $this->salesmanRepository->update($salesman, $data);
    }

    public function delete(string $id): bool
    {
        $salesman = $this->salesmanRepository->findById($id);
        if (! $salesman) {
            throw new \Exception('Salesman tidak ditemukan.');
        }
        return $this->salesmanRepository->delete($salesman);
    }

    public function getAll()
    {
        return $this->salesmanRepository->getAll();
    }
}
