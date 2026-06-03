<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Database\Eloquent\Collection;

class LocationBinService
{
    public function __construct(
        protected LocationBinRepository $binRepository
    ) {}

    public function getByLocation(int $locationId): Collection
    {
        return $this->binRepository->findByLocation($locationId);
    }

    public function getById(int $id): ?LocationBin
    {
        return $this->binRepository->findById($id);
    }

    public function getDefaultBin(int $locationId): ?LocationBin
    {
        return $this->binRepository->getDefaultBin($locationId);
    }

    public function create(array $data): LocationBin
    {
        $data['bin_final_code'] = $this->generateFinalCode($data);

        return $this->binRepository->create($data);
    }

    public function update(int $id, array $data): bool
    {
        if (isset($data['floor_code']) || isset($data['row_code']) || isset($data['column_code']) || isset($data['bin_code'])) {
            $bin = $this->binRepository->findById($id);
            $merged = array_merge($bin->toArray(), $data);
            $data['bin_final_code'] = $this->generateFinalCode($merged);
        }

        return $this->binRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        $bin = $this->binRepository->findById($id);
        if (!$bin) {
            return false;
        }

        if ($bin->is_inbound) {
            throw new \Exception('Bin inbound (default) tidak dapat dihapus.');
        }

        return $this->binRepository->delete($id);
    }

    protected function generateFinalCode(array $data): string
    {
        $parts = array_filter([
            $data['floor_code'] ?? null,
            $data['row_code'] ?? null,
            $data['column_code'] ?? null,
            $data['bin_code'] ?? null,
        ]);

        return !empty($parts) ? implode('-', $parts) : 'DEFAULT';
    }
}
