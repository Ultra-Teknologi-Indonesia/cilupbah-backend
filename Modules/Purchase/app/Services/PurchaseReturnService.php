<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseReturnRepository;
use Modules\Purchase\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    public function __construct(
        protected PurchaseReturnRepository $returnRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->returnRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchaseReturn
    {
        return $this->returnRepository->findById($id);
    }

    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data) {
            $data['return_number'] = $data['return_number'] ?? $this->returnRepository->generateReturnNo();
            $data['status'] = $data['status'] ?? PurchaseReturn::STATUS_DRAFT;

            $totalAmount = 0;
            foreach (($data['items'] ?? []) as $item) {
                $totalAmount += $item['qty'] * ($item['unit_price'] ?? 0);
            }
            $data['total_amount'] = $totalAmount;

            $return = $this->returnRepository->create($data);

            foreach (($data['items'] ?? []) as $itemData) {
                $itemData['purchase_return_id'] = $return->id;
                $itemData['subtotal'] = $itemData['qty'] * ($itemData['unit_price'] ?? 0);
                $this->returnRepository->createItem($itemData);
            }

            return $return->load('items.product:id,name,sku');
        });
    }

    public function delete(string $id): bool
    {
        $return = $this->returnRepository->findById($id);
        if (! $return) {
            throw new \Exception('Purchase return tidak ditemukan.');
        }
        if ($return->status !== PurchaseReturn::STATUS_DRAFT) {
            throw new \Exception('Hanya return berstatus DRAFT yang bisa dihapus.');
        }
        return $this->returnRepository->delete($return);
    }

    public function getUnpaid(int $limit = 10)
    {
        return $this->returnRepository->getUnpaid($limit);
    }
}
