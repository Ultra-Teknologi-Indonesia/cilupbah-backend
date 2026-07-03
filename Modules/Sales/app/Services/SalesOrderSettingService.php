<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\SalesOrderSetting;
use Modules\Sales\Repositories\SalesOrderSettingRepository;

class SalesOrderSettingService
{
    public function __construct(
        private readonly SalesOrderSettingRepository $repository,
    ) {}

    public function get(): SalesOrderSetting
    {
        return $this->repository->current();
    }

    public function save(array $data): SalesOrderSetting
    {
        return $this->repository->update($data);
    }

    public function autoAcceptCancelOnPacked(): bool
    {
        return (bool) $this->get()->auto_accept_cancel_on_packed;
    }
}
