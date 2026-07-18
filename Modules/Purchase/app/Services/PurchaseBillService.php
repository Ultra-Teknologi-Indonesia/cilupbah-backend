<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseBillRepository;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Inventory\Repositories\InventoryRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class PurchaseBillService
{
    use StockLockable;

    public function __construct(
        protected PurchaseBillRepository $billRepository,
        protected InventoryRepository $inventoryRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->billRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchaseBill
    {
        return $this->billRepository->findById($id);
    }

    public function create(array $data): PurchaseBill
    {
        return DB::transaction(function () use ($data) {
            $data['bill_number'] = $data['bill_number'] ?? $this->billRepository->generateBillNo();
            $data['status'] = PurchaseBill::STATUS_OPEN;
            $data['created_by'] = auth()->user()?->name ?? 'system';
            $data['paid_amount'] = $data['payment_amount'] ?? 0;

            $totals = $this->calculateTotals($data['items'] ?? [], $data['is_tax_included'] ?? false);
            $data = array_merge($data, $totals);

            if ($data['paid_amount'] >= $data['total_amount'] && $data['total_amount'] > 0) {
                $data['status'] = PurchaseBill::STATUS_PAID;
            } elseif ($data['paid_amount'] > 0) {
                $data['status'] = PurchaseBill::STATUS_PARTIAL;
            }

            $bill = $this->billRepository->create($data);

            foreach (($data['items'] ?? []) as $itemData) {
                $itemData['purchase_bill_id'] = $bill->id;
                $this->calculateItemAmounts($itemData);
                $this->billRepository->createItem($itemData);

                $this->adjustOnOrder($itemData['item_id'], $bill->location_id, -$itemData['qty']);
            }

            return $bill->load('items.variant.product:id,name');
        });
    }

    public function update(string $id, array $data): PurchaseBill
    {
        return DB::transaction(function () use ($id, $data) {
            $bill = $this->billRepository->findById($id);

            if (!$bill) {
                throw new \Exception('Tagihan tidak ditemukan.');
            }

            if (in_array($bill->status, [PurchaseBill::STATUS_PAID, PurchaseBill::STATUS_CANCELLED])) {
                throw new \Exception('Tagihan berstatus ' . $bill->status . ' tidak bisa diedit.');
            }

            $totals = $this->calculateTotals($data['items'] ?? [], $data['is_tax_included'] ?? false);
            $data = array_merge($data, $totals);

            if (isset($data['items'])) {
                $bill->load('items');

                foreach ($bill->items as $item) {
                    $this->adjustOnOrder($item->item_id, $bill->location_id, $item->qty);
                }

                $bill->items()->delete();

                foreach ($data['items'] as $itemData) {
                    $itemData['purchase_bill_id'] = $bill->id;
                    $this->calculateItemAmounts($itemData);
                    $this->billRepository->createItem($itemData);

                    $this->adjustOnOrder($itemData['item_id'], $data['location_id'] ?? $bill->location_id, -$itemData['qty']);
                }
            }

            $bill->update($data);

            return $this->getById($id);
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $bill = $this->billRepository->findById($id);
            if (!$bill) {
                throw new \Exception('Tagihan tidak ditemukan.');
            }
            if (in_array($bill->status, [PurchaseBill::STATUS_PAID])) {
                throw new \Exception('Tagihan yang sudah lunas tidak bisa dihapus.');
            }

            $bill->load('items');

            foreach ($bill->items as $item) {
                $this->adjustOnOrder($item->item_id, $bill->location_id, $item->qty);
            }

            return $this->billRepository->delete($bill);
        });
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

    private function calculateItemAmounts(array &$item): void
    {
        $price = $item['unit_price'] ?? 0;
        $qty = $item['qty'] ?? 0;
        $discPercent = $item['disc'] ?? 0;

        $lineTotal = $price * $qty;
        $item['disc_amount'] = round($lineTotal * $discPercent / 100, 2);
        $item['tax_amount'] = $item['tax_amount'] ?? 0;
        $item['amount'] = round($lineTotal - $item['disc_amount'] + $item['tax_amount'], 2);
    }

    private function calculateTotals(array $items, bool $isTaxIncluded): array
    {
        $subTotal = 0;
        $totalDisc = 0;
        $totalTax = 0;

        foreach ($items as $item) {
            $price = $item['unit_price'] ?? 0;
            $qty = $item['qty'] ?? 0;
            $discPercent = $item['disc'] ?? 0;

            $lineTotal = $price * $qty;
            $discAmount = round($lineTotal * $discPercent / 100, 2);
            $taxAmount = $item['tax_amount'] ?? 0;

            $subTotal += $lineTotal;
            $totalDisc += $discAmount;
            $totalTax += $taxAmount;
        }

        return [
            'sub_total'    => round($subTotal, 2),
            'total_disc'   => round($totalDisc, 2),
            'total_tax'    => round($totalTax, 2),
            'total_amount' => round($subTotal - $totalDisc + $totalTax, 2),
        ];
    }

    private function adjustOnOrder(string $itemId, string $locationId, int $qty): void
    {

    }
}
