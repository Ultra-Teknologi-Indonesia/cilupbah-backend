<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesReturnSettlement;
use Modules\Sales\Models\SalesReturnSettlementInvoice;
use Modules\Sales\Models\SalesReturnSettlementRefund;
use Modules\Sales\Repositories\SalesReturnSettlementRepository;

class SalesReturnSettlementService
{
    public function __construct(
        protected SalesReturnSettlementRepository $repo,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->repo->getAllPaginated($limit);
    }

    public function getById(string $id): ?SalesReturnSettlement
    {
        return $this->repo->findById($id);
    }

    public function delete(string $id): bool
    {
        return $this->repo->delete($id);
    }

    public function getInvoices(int $limit = 10)
    {
        return $this->repo->getInvoices($limit);
    }

    public function getInvoiceById(string $id): ?SalesReturnSettlementInvoice
    {
        return $this->repo->findInvoiceById($id);
    }

    public function createInvoice(array $data): SalesReturnSettlementInvoice
    {
        return DB::transaction(function () use ($data) {
            $entry = $this->repo->createInvoice($data);

            $settlement = SalesReturnSettlement::findOrFail($data['settlement_id']);
            $settlement->total_amount = $settlement->invoices()->sum('amount');
            $settlement->save();

            return $entry;
        });
    }

    public function getRefunds(int $limit = 10)
    {
        return $this->repo->getRefunds($limit);
    }

    public function getRefundById(string $id): ?SalesReturnSettlementRefund
    {
        return $this->repo->findRefundById($id);
    }

    public function createRefund(array $data): SalesReturnSettlementRefund
    {
        return $this->repo->createRefund($data);
    }
}
