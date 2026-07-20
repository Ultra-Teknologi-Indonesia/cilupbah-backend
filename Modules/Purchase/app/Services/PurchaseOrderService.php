<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Enums\PurchaseActivityAction;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Warehouse\Services\CompanyProfileService;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderService
{
    use StockLockable;

    public function __construct(
        protected PurchaseOrderRepository $poRepository,
        protected InboundService $inboundService,
        protected InventoryRepository $inventoryRepository,
        protected CompanyProfileService $companyProfile,
        protected PurchaseOrderActivityLogger $activityLogger,
    ) {}

    public function downloadPdf(string $id)
    {
        $po = PurchaseOrder::with(['contact:id,name', 'location:id,location_name', 'items.variant:id,sku,product_id', 'items.variant.product:id,name'])
            ->findOrFail($id);

        $company = $this->companyProfile->forPdf();

        $pdf = Pdf::loadView('purchase::pdf.purchase-order', [
            'po'      => $po,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream("PO-{$po->po_number}.pdf");
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->poRepository->getAllPaginated($limit);
    }

    public function getReceivable(int $limit = 10)
    {
        return $this->poRepository->getReceivable($limit);
    }

    public function getById(string $id): ?PurchaseOrder
    {
        return $this->poRepository->findById($id);
    }

    public function getPaginatedItems(string $poId, int $perPage)
    {
        return $this->poRepository->getPaginatedItems($poId, $perPage);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $data['po_number'] = $data['po_number'] ?? $this->poRepository->generatePoNumber();
            $data['status'] = PurchaseOrder::STATUS_OPEN;
            $data['created_by'] = auth()->user()?->name ?? 'system';

            $totals = $this->calculateTotals($data['items'], $data['is_tax_included'] ?? false);
            $data = array_merge($data, $totals);

            $po = $this->poRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['purchase_order_id'] = $po->id;
                $this->calculateItemAmounts($itemData);
                $this->poRepository->createItem($itemData);
            }

            $this->activityLogger->log($po, PurchaseActivityAction::CREATED, [
                'new_values' => [
                    'po_number'    => $po->po_number,
                    'total_amount' => (float) $po->total_amount,
                    'item_count'   => count($data['items']),
                ],
            ]);

            return $po->fresh()->load('items.variant.product:id,name');
        });
    }

    public function update(string $id, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            $this->inboundService->assertPurchaseOrderEditable($po);

            unset($data['status']);

            $headerBefore = $po->only(array_keys($data));
            $statusBefore = $po->status;
            $itemsBefore = $this->snapshotItems($po);

            if (isset($data['items'])) {
                $this->reverseReceiptsForShrunkLines($po, $data['items']);

                foreach ($data['items'] as &$itemData) {
                    $this->calculateItemAmounts($itemData);
                }
                unset($itemData);

                $this->poRepository->syncItems($po, $data['items']);
                $po->load('items');

                $this->logItemDiff($po, $itemsBefore);

                $data = array_merge($data, $this->calculateTotals(
                    $po->items->map(fn ($item) => [
                        'unit_price' => (float) $item->unit_price,
                        'qty'        => (int) $item->qty,
                        'disc'       => (float) $item->disc,
                        'tax_amount' => (float) $item->tax_amount,
                    ])->all(),
                    $data['is_tax_included'] ?? (bool) $po->is_tax_included,
                ));
            }

            $po->update($data);

            $this->activityLogger->logHeaderChanges($po, $headerBefore, $data);

            $newStatus = $po->recomputeStatus();
            if ($newStatus !== $po->status) {
                $this->poRepository->updateStatus($po, $newStatus);
            }

            $this->activityLogger->logStatusChanged($po, $statusBefore, $po->fresh()->status);

            $this->inboundService->resyncDraftFromPO($po->fresh()->load('items'));

            return $this->getById($id);
        });
    }

    /**
     * Potret baris item sebelum sync, dipakai untuk mendiff riwayat. SKU ikut
     * disalin karena baris yang dihapus tidak bisa lagi di-join ke variant
     * setelah sync selesai.
     *
     * @return array<string,array>
     */
    private function snapshotItems(PurchaseOrder $po): array
    {
        $po->load('items.variant:id,sku');

        return $po->items->mapWithKeys(fn ($item) => [$item->id => [
            'sku'           => $item->variant?->sku,
            'qty'           => (int) $item->qty,
            'received_qty'  => (int) $item->received_qty,
            'unit_price'    => (float) $item->unit_price,
            'disc'          => (float) $item->disc,
            'disc_amount'   => (float) $item->disc_amount,
            'shipping_cost' => (float) $item->shipping_cost,
            'description'   => $item->description,
            'unit'          => $item->unit,
        ]])->all();
    }

    /**
     * @param array<string,array> $before
     */
    private function logItemDiff(PurchaseOrder $po, array $before): void
    {
        $after = $this->snapshotItems($po);

        foreach ($after as $itemId => $row) {
            if (! isset($before[$itemId])) {
                $this->activityLogger->logItemAdded(
                    $po,
                    ['id' => $itemId, 'qty' => $row['qty'], 'unit_price' => $row['unit_price']],
                    $row['sku'],
                );
                continue;
            }

            $this->activityLogger->logItemChanged($po, $itemId, $row['sku'], $before[$itemId], $row);
        }

        foreach ($before as $itemId => $row) {
            if (isset($after[$itemId])) {
                continue;
            }

            $this->activityLogger->logItemRemoved(
                $po,
                $itemId,
                $row['sku'],
                $row['qty'],
                $row['received_qty'],
            );
        }
    }

    /**
     * Baris yang qty-nya diturunkan di bawah jumlah yang sudah diterima -- atau
     * dihapus sama sekali -- harus menarik balik penerimaannya sampai ke stok di
     * rak. Dijalankan SEBELUM syncItems supaya received_qty sudah turun saat
     * baris hendak dihapus, dan seluruh kekurangan stok dilaporkan sekaligus di
     * muka alih-alih gagal di tengah cascade.
     */
    private function reverseReceiptsForShrunkLines(PurchaseOrder $po, array $items): void
    {
        $po->load('items');

        $targetQtyByItemId = collect($items)
            ->filter(fn ($row) => ! empty($row['id']))
            ->mapWithKeys(fn ($row) => [$row['id'] => (int) ($row['qty'] ?? 0)]);

        $reversalByVariant = [];

        foreach ($po->items as $poItem) {
            $received = (int) $poItem->received_qty;

            if ($received <= 0) {
                continue;
            }

            $targetQty = $targetQtyByItemId->has($poItem->id)
                ? $targetQtyByItemId->get($poItem->id)
                : 0;

            if ($targetQty >= $received) {
                continue;
            }

            $reversalByVariant[$poItem->item_id] =
                ($reversalByVariant[$poItem->item_id] ?? 0) + ($received - $targetQty);
        }

        if (empty($reversalByVariant)) {
            return;
        }

        $this->inboundService->assertPurchaseReversalPossible($po, $reversalByVariant);

        $actorId = auth()->id();
        $skuByVariant = \Modules\Product\Models\ProductVariant::whereIn('id', array_keys($reversalByVariant))
            ->pluck('sku', 'id');

        foreach ($reversalByVariant as $variantId => $qty) {
            $this->inboundService->reversePurchaseReceiptForVariant($po, $variantId, $qty, $actorId);

            $this->activityLogger->logReceiptReversed(
                $po,
                $skuByVariant[$variantId] ?? null,
                $qty,
                'Kuantitas pesanan diturunkan di bawah jumlah yang sudah diterima',
            );
        }

        $this->inboundService->recomputePurchaseOrderReceived($po);
        $po->load('items');
    }

    public function receive(string $poId, array $data): array
    {
        return DB::transaction(function () use ($poId, $data) {
            $po = $this->poRepository->findByIdForUpdate($poId);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if (!$po->isReceivable()) {
                throw new \Exception("PO berstatus {$po->status}, tidak bisa di-receive.");
            }

            $poItemsDict = $po->items->keyBy('id');
            $inboundItems = [];

            foreach ($data['items'] as $receiveItem) {
                $poItem = $poItemsDict->get($receiveItem['purchase_order_item_id']);
                if (!$poItem) {
                    throw new \Exception("PO Item ID {$receiveItem['purchase_order_item_id']} tidak ditemukan.");
                }

                $acceptedQty = $receiveItem['qty'];
                $rejectedQty = $receiveItem['rejected_qty'] ?? 0;
                $totalQty = $acceptedQty + $rejectedQty;

                $pending = $poItem->pendingQty();
                if ($totalQty > $pending) {
                    throw new \Exception("Qty receive ({$totalQty}) melebihi pending ({$pending}) untuk item {$poItem->item_id}.");
                }

                $this->poRepository->updateItemReceivedQty($poItem->id, $totalQty);

                $inboundItems[] = [
                    'item_id'        => $poItem->item_id,
                    'expected_qty'   => $totalQty,
                    'accepted_qty'   => $acceptedQty,
                    'rejected_qty'   => $rejectedQty,
                    'rejection_note' => $receiveItem['rejection_note'] ?? null,
                    'notes'          => $receiveItem['notes'] ?? null,
                ];
            }

            $statusBefore = $po->status;
            $this->poRepository->updateStatus($po, $po->recomputeStatus());

            $this->activityLogger->log($po, PurchaseActivityAction::RECEIVED, [
                'new_values' => [
                    'total_qty'  => array_sum(array_column($inboundItems, 'expected_qty')),
                    'item_count' => count($inboundItems),
                ],
            ]);
            $this->activityLogger->logStatusChanged($po, $statusBefore, $po->fresh()->status);

            $inbound = $this->inboundService->receiveFromPO([
                'location_id'      => $data['location_id'] ?? $po->location_id,
                'reference_number' => $data['reference_number'] ?? $po->po_number,
                'source_id'        => $po->id,
                'expected_date'    => $data['receive_date'] ?? now()->toDateString(),
                'created_by'       => auth()->user()?->name ?? 'system',
                'notes'            => $data['notes'] ?? null,
                'items'            => $inboundItems,
            ]);

            return [
                'purchase_order' => $this->getById($poId),
                'inbound'        => $inbound,
            ];
        });
    }

    /**
     * PO bisa dihapus di status mana pun. Penerimaan yang terlanjur tercatat
     * ditarik balik lebih dulu, lalu seluruh jejak hilir dilepas: inbound
     * dinetralkan, tagihan dan pembayarannya ikut dihapus.
     *
     * Penghapusan ini permanen — tidak ada SoftDeletes, dan riwayat PO ikut
     * hilang lewat cascade purchase_order_activities.
     */
    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            $this->reverseAllReceipts($po);
            $this->detachInbounds($po);
            $this->deleteBillsAndPayments($po);

            return $this->poRepository->delete($po);
        });
    }

    /**
     * Tarik balik seluruh penerimaan PO sebelum dokumennya dihapus.
     *
     * Sama seperti reverseReceiptsForShrunkLines(), kekurangan stok diperiksa
     * di muka lewat assertPurchaseReversalPossible() supaya penolakan bisa
     * merinci per SKU/rak alih-alih gagal di tengah cascade. Barang yang sudah
     * dipicking atau terjual akan menolak penghapusan di sini.
     */
    private function reverseAllReceipts(PurchaseOrder $po): void
    {
        $po->load('items');

        $reversalByVariant = [];

        foreach ($po->items as $poItem) {
            $received = (int) $poItem->received_qty;

            if ($received <= 0) {
                continue;
            }

            $reversalByVariant[$poItem->item_id] =
                ($reversalByVariant[$poItem->item_id] ?? 0) + $received;
        }

        if (empty($reversalByVariant)) {
            return;
        }

        $this->inboundService->assertPurchaseReversalPossible($po, $reversalByVariant);

        $actorId = auth()->id();

        foreach ($reversalByVariant as $variantId => $qty) {
            $this->inboundService->reversePurchaseReceiptForVariant($po, $variantId, $qty, $actorId);
        }
    }

    /**
     * inbounds menaut ke PO lewat source_type/source_id tanpa foreign key, jadi
     * menghapus PO tidak memicu cascade apa pun dan akan meninggalkan penunjuk
     * yatim. Inbound-nya sendiri tidak bisa dihapus karena putaway_sources
     * memeganginya dengan restrictOnDelete, jadi cukup dinetralkan.
     */
    private function detachInbounds(PurchaseOrder $po): void
    {
        Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->update([
                'status'      => Inbound::STATUS_CANCELLED,
                'source_type' => null,
                'source_id'   => null,
            ]);
    }

    /**
     * purchase_bills memakai SET NULL sehingga tagihan akan bertahan tanpa
     * induk. Pembayaran dihapus lebih dulu karena FK-nya ke bill restrictive.
     */
    private function deleteBillsAndPayments(PurchaseOrder $po): void
    {
        $billIds = PurchaseBill::where('purchase_order_id', $po->id)->pluck('id');

        if ($billIds->isEmpty()) {
            return;
        }

        PurchasePayment::whereIn('purchase_bill_id', $billIds)->delete();
        PurchaseBill::whereIn('id', $billIds)->delete();
    }

    public function bulkDelete(array $ids): array
    {
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $this->delete($id);
                $deleted++;
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
            }
        }

        return [
            'deleted' => $deleted,
            'failed'  => count($errors),
            'errors'  => $errors,
        ];
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

}
