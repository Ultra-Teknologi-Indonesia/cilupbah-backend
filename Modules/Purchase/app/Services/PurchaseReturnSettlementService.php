<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseReturnSettlementRepository;
use Modules\Purchase\Models\PurchaseReturnSettlement;
use Modules\Purchase\Models\PurchaseReturnSettlementBill;
use Modules\Purchase\Models\PurchaseReturnSettlementRefund;
use Illuminate\Support\Facades\DB;

class PurchaseReturnSettlementService
{
    public function __construct(
        protected PurchaseReturnSettlementRepository $settlementRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->settlementRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchaseReturnSettlement
    {
        return $this->settlementRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $settlement = $this->settlementRepository->findById($id);
        if (! $settlement) {
            throw new \Exception('Settlement tidak ditemukan.');
        }
        if ($settlement->status !== PurchaseReturnSettlement::STATUS_DRAFT) {
            throw new \Exception('Hanya settlement berstatus DRAFT yang bisa dihapus.');
        }
        return $this->settlementRepository->delete($settlement);
    }

    public function getBills(int $limit = 10)
    {
        return $this->settlementRepository->getBills($limit);
    }

    public function getBillById(string $id): ?PurchaseReturnSettlementBill
    {
        return $this->settlementRepository->findBillById($id);
    }

    public function createBill(array $data): PurchaseReturnSettlementBill
    {
        return DB::transaction(function () use ($data) {
            $bill = $this->settlementRepository->createBill($data);
            $this->recalcSettlementTotal($data['settlement_id']);
            return $bill->load(['settlement', 'bill']);
        });
    }

    public function getRefunds(int $limit = 10)
    {
        return $this->settlementRepository->getRefunds($limit);
    }

    public function getRefundById(string $id): ?PurchaseReturnSettlementRefund
    {
        return $this->settlementRepository->findRefundById($id);
    }

    public function createRefund(array $data): PurchaseReturnSettlementRefund
    {
        return DB::transaction(function () use ($data) {
            $refund = $this->settlementRepository->createRefund($data);
            $this->recalcSettlementTotal($data['settlement_id']);
            return $refund->load('settlement');
        });
    }

    private function recalcSettlementTotal(string $settlementId): void
    {
        $settlement = $this->settlementRepository->find($settlementId);
        if (! $settlement) return;

        $billTotal = $settlement->bills()->sum('amount');
        $refundTotal = $settlement->refunds()->sum('amount');
        $settlement->total_amount = bcadd($billTotal, $refundTotal, 2);
        $settlement->save();
    }
}
