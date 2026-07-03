<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesOrderSetting;

class SalesOrderSettingRepository
{
    public function current(): SalesOrderSetting
    {
        return SalesOrderSetting::query()->firstOrCreate([], [
            'auto_accept_cancel_on_packed' => true,
        ]);
    }

    public function update(array $data): SalesOrderSetting
    {
        $setting = $this->current();
        $setting->update($data);

        return $setting->refresh();
    }
}
