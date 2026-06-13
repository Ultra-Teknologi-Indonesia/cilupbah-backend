<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\SalesReturnSetting;
use Modules\Sales\Repositories\SalesReturnSettingRepository;

/**
 * Pengaturan retur penjualan (Jubelio: /systemsetting/sales-return-setting).
 * Semua accessor mengembalikan nilai efektif dengan default aman → konsumen tak pernah error.
 */
class SalesReturnSettingService
{
    public function __construct(
        private readonly SalesReturnSettingRepository $repository,
    ) {}

    public function get(): SalesReturnSetting
    {
        return $this->repository->current();
    }

    public function save(array $data): SalesReturnSetting
    {
        return $this->repository->update($data);
    }

    public function autoAccept(): bool
    {
        return (bool) $this->get()->auto_accept;
    }

    public function restockLocationId(): ?string
    {
        return $this->get()->default_restock_location_id;
    }

    /** @return array<int, string> */
    public function allowedConditions(): array
    {
        $values = $this->get()->allowed_conditions;

        return ! empty($values) ? $values : SalesReturnSetting::DEFAULT_CONDITIONS;
    }

    /** @return array<int, string> */
    public function allowedRefundMethods(): array
    {
        $values = $this->get()->allowed_refund_methods;

        return ! empty($values) ? $values : SalesReturnSetting::DEFAULT_REFUND_METHODS;
    }

    public function returnValidityDays(): ?int
    {
        return $this->get()->return_validity_days;
    }
}
