<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseSerialNumberRepository;
use Illuminate\Support\Facades\DB;

class PurchaseSerialNumberService
{
    public function __construct(
        protected PurchaseSerialNumberRepository $serialRepository
    ) {}

    public function getByBillItemId(string $billDetailId)
    {
        return $this->serialRepository->getByBillItemId($billDetailId);
    }

    public function markPrinted(array $data): int
    {
        return DB::transaction(function () use ($data) {
            return $this->serialRepository->markPrintedByIds(
                $data['ids'],
                $data['printed_by'] ?? null
            );
        });
    }
}
