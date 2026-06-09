<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\CourierRepository;
use Modules\Outbound\Models\Courier;

class CourierService
{
    public function __construct(
        protected CourierRepository $courierRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->courierRepository->getAllPaginated($limit);
    }

    public function getAll()
    {
        return $this->courierRepository->getAll();
    }

    public function getById(string $id): ?Courier
    {
        return $this->courierRepository->findById($id);
    }

    public function getByCode(string $code): ?Courier
    {
        return $this->courierRepository->findByCode($code);
    }

    public function create(array $data): Courier
    {
        return $this->courierRepository->create($data);
    }

    public function update(string $id, array $data): Courier
    {
        $courier = $this->courierRepository->findById($id);

        if (!$courier) {
            throw new \Exception('Courier tidak ditemukan.');
        }

        $this->courierRepository->update($id, $data);

        return $this->courierRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $courier = $this->courierRepository->findById($id);

        if (!$courier) {
            throw new \Exception('Courier tidak ditemukan.');
        }

        return $this->courierRepository->delete($id);
    }
}
