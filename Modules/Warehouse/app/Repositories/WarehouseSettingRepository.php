<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\WarehouseSetting;

class WarehouseSettingRepository
{
    public function current(): WarehouseSetting
    {
        return WarehouseSetting::query()->firstOrCreate([], [
            'use_warehouse_layout' => true,
        ]);
    }

    public function update(array $data): WarehouseSetting
    {
        $setting = $this->current();
        $setting->update($data);

        return $setting->refresh();
    }
}
