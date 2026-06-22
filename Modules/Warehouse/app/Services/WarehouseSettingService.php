<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Models\WarehouseSetting;
use Modules\Warehouse\Repositories\WarehouseSettingRepository;

class WarehouseSettingService
{
    public function __construct(
        private readonly WarehouseSettingRepository $repository,
    ) {}

    public function get(): WarehouseSetting
    {
        return $this->repository->current();
    }

    public function save(array $data): WarehouseSetting
    {
        return $this->repository->update($data);
    }

    public function useWarehouseLayout(): bool
    {
        return (bool) $this->get()->use_warehouse_layout;
    }
}
