<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesReturnSetting;

class SalesReturnSettingRepository
{

    public function current(): SalesReturnSetting
    {
        return SalesReturnSetting::query()->firstOrCreate([], [
            'auto_accept' => false,
            'allowed_conditions' => SalesReturnSetting::DEFAULT_CONDITIONS,
            'allowed_refund_methods' => SalesReturnSetting::DEFAULT_REFUND_METHODS,
        ]);
    }

    public function update(array $data): SalesReturnSetting
    {
        $setting = $this->current();
        $setting->update($data);

        return $setting->refresh();
    }
}
