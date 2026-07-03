<?php

namespace Modules\Sales\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesReturn;
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

    public function create(array $data): SalesReturnSettlement
    {
        return DB::transaction(function () use ($data) {
            $return = SalesReturn::findOrFail($data['return_id']);

            if ($return->status !== SalesReturn::STATUS_COMPLETED) {
                throw new \InvalidArgumentException("Return harus berstatus COMPLETED sebelum membuat settlement.");
            }

            if ($return->settlement) {
                throw new \InvalidArgumentException("Return sudah memiliki settlement.");
            }

            $data['settlement_number'] = $this->repo->generateSettlementNo();
            $data['status'] = SalesReturnSettlement::STATUS_DRAFT;
            $data['total_amount'] = 0;

            return $this->repo->create($data);
        });
    }

    public function confirm(string $id): SalesReturnSettlement
    {
        return DB::transaction(function () use ($id) {
            $settlement = SalesReturnSettlement::lockForUpdate()->findOrFail($id);

            if ($settlement->status !== SalesReturnSettlement::STATUS_DRAFT) {
                throw new \InvalidArgumentException("Settlement harus berstatus DRAFT untuk dikonfirmasi.");
            }

            if ($settlement->invoices()->count() === 0 && $settlement->refunds()->count() === 0) {
                throw new \InvalidArgumentException("Settlement harus memiliki minimal satu invoice deduction atau refund.");
            }

            $settlement->update(['status' => SalesReturnSettlement::STATUS_CONFIRMED]);

            return $this->repo->findById($id);
        });
    }

    public function complete(string $id): SalesReturnSettlement
    {
        return DB::transaction(function () use ($id) {
            $settlement = SalesReturnSettlement::lockForUpdate()->findOrFail($id);

            if ($settlement->status !== SalesReturnSettlement::STATUS_CONFIRMED) {
                throw new \InvalidArgumentException("Settlement harus berstatus CONFIRMED untuk di-complete.");
            }

            $settlement->update(['status' => SalesReturnSettlement::STATUS_COMPLETED]);

            return $this->repo->findById($id);
        });
    }

    public function delete(string $id): bool
    {
        $settlement = SalesReturnSettlement::findOrFail($id);

        if ($settlement->status !== SalesReturnSettlement::STATUS_DRAFT) {
            throw new \InvalidArgumentException("Hanya settlement berstatus DRAFT yang bisa dihapus.");
        }

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

            $this->recomputeTotal($data['settlement_id']);

            return $entry;
        });
    }

    public function deleteInvoice(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $invoice = SalesReturnSettlementInvoice::findOrFail($id);
            $settlementId = $invoice->settlement_id;
            $invoice->delete();

            $this->recomputeTotal($settlementId);

            return true;
        });
    }

    private function recomputeTotal(string $settlementId): void
    {
        $settlement = SalesReturnSettlement::findOrFail($settlementId);
        $settlement->total_amount = (float) $settlement->invoices()->sum('amount')
            + (float) $settlement->refunds()->sum('amount');
        $settlement->save();
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
        return DB::transaction(function () use ($data) {
            $entry = $this->repo->createRefund($data);

            if (! empty($data['settlement_id'])) {
                $this->recomputeTotal($data['settlement_id']);
            }

            return $entry;
        });
    }

    public function deleteRefund(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $refund = SalesReturnSettlementRefund::find($id);
            if (! $refund) {
                return false;
            }

            $settlementId = $refund->settlement_id;
            $refund->delete();

            if ($settlementId) {
                $this->recomputeTotal($settlementId);
            }

            return true;
        });
    }
}
