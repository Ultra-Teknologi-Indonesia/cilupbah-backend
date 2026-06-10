<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseBillRepository;
use Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Facades\DB;

class PurchaseBillService
{
    public function __construct(
        protected PurchaseBillRepository $billRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->billRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchaseBill
    {
        return $this->billRepository->findById($id);
    }

    public function createOrUpdate(array $data): PurchaseBill
    {
        return DB::transaction(function () use ($data) {
            $data['bill_number'] = $data['bill_number'] ?? $this->billRepository->generateBillNo();
            $data['status'] = $data['status'] ?? PurchaseBill::STATUS_DRAFT;

            $totalAmount = 0;
            foreach (($data['items'] ?? []) as $item) {
                $totalAmount += $item['qty'] * ($item['unit_price'] ?? 0);
            }
            $data['total_amount'] = $totalAmount;
            $data['paid_amount'] = $data['paid_amount'] ?? 0;

            $bill = $this->billRepository->create($data);

            foreach (($data['items'] ?? []) as $itemData) {
                $itemData['purchase_bill_id'] = $bill->id;
                $itemData['subtotal'] = $itemData['qty'] * ($itemData['unit_price'] ?? 0);
                $this->billRepository->createItem($itemData);
            }

            return $bill->load('items.product:id,name,sku');
        });
    }

    public function delete(string $id): bool
    {
        $bill = $this->billRepository->findById($id);
        if (! $bill) {
            throw new \Exception('Bill tidak ditemukan.');
        }
        if ($bill->status !== PurchaseBill::STATUS_DRAFT) {
            throw new \Exception('Hanya bill berstatus DRAFT yang bisa dihapus.');
        }
        return $this->billRepository->delete($bill);
    }

    public function getUnpaid(int $limit = 10)
    {
        return $this->billRepository->getUnpaid($limit);
    }

    public function getOverdue(int $limit = 10)
    {
        return $this->billRepository->getOverdue($limit);
    }

    public function getForReturn(int $limit = 10)
    {
        return $this->billRepository->getForReturn($limit);
    }
}
