<?php

namespace Modules\Supplier\Services;

use Modules\Supplier\Repositories\SupplierRepository;
use Modules\Supplier\Models\Supplier;
use Illuminate\Support\Str;

class SupplierService
{
    public function __construct(
        protected SupplierRepository $supplierRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->supplierRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Supplier
    {
        return $this->supplierRepository->findById($id);
    }

    public function create(array $data): Supplier
    {
        if (empty($data['code'])) {
            $data['code'] = 'SUP-' . Str::upper(Str::random(6));
        }

        return $this->supplierRepository->create($data);
    }

    public function update(string $id, array $data): Supplier
    {
        $supplier = $this->supplierRepository->findById($id);

        if (! $supplier) {
            throw new \Exception('Supplier tidak ditemukan.');
        }

        return $this->supplierRepository->update($supplier, $data);
    }

    public function delete(string $id): bool
    {
        $supplier = $this->supplierRepository->findById($id);

        if (! $supplier) {
            throw new \Exception('Supplier tidak ditemukan.');
        }

        return $this->supplierRepository->delete($supplier);
    }

    public function getActiveSuppliers()
    {
        return $this->supplierRepository->getActiveSuppliers();
    }
}
