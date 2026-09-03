<?php

namespace Modules\Sales\Services;

use App\Exceptions\UserFacingException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Sales\Models\OrderBuyerConfirmation;
use Modules\Sales\Repositories\OrderDirectCompletionRepository;
use Modules\Sales\Repositories\BuyerConfirmationRepository;

class BuyerConfirmationService
{
    public const NOTIF_PERMISSION = 'view-pesanan';

    public function __construct(
        protected OrderDirectCompletionRepository $repository,
        protected BuyerConfirmationRepository $confirmationRepository,
        protected SalesOrderService $orderService,
        protected NotificationDispatcher $notifications,
    ) {}

    public function paginate(string $state)
    {
        return $this->confirmationRepository->paginate($state);
    }

    public function forOrder(string $orderId)
    {
        return $this->confirmationRepository->forOrder($orderId);
    }

    public function decide(string $confirmationId, string $outcome, ?string $replacementSku, ?string $note): OrderBuyerConfirmation
    {
        if (! in_array($outcome, OrderBuyerConfirmation::OUTCOMES, true)) {
            throw new UserFacingException('Keputusan pembeli tidak dikenal.');
        }

        return DB::transaction(function () use ($confirmationId, $outcome, $replacementSku, $note) {
            $confirmation = OrderBuyerConfirmation::whereKey($confirmationId)->lockForUpdate()->firstOrFail();

            if ($confirmation->resolved_at !== null) {
                throw new UserFacingException('Konfirmasi ini sudah diselesaikan.');
            }

            $actorId = Auth::id() ?: null;
            $now = now();

            match ($outcome) {
                OrderBuyerConfirmation::OUTCOME_CANCEL => $this->applyCancel($confirmation, $note, $actorId),
                OrderBuyerConfirmation::OUTCOME_REPLACE => $this->applyReplace($confirmation, $replacementSku),
                OrderBuyerConfirmation::OUTCOME_REMOVE => $this->applyRemove($confirmation),
                OrderBuyerConfirmation::OUTCOME_WAIT => null,
            };

            $confirmation->forceFill([
                'outcome' => $outcome,
                'note' => $note,
                'confirmed_by' => $actorId,
                'confirmed_at' => $now,
                'resolved_at' => $outcome === OrderBuyerConfirmation::OUTCOME_WAIT ? null : $now,
            ])->save();

            if ($outcome === OrderBuyerConfirmation::OUTCOME_CANCEL) {
                $this->resolveSiblings($confirmation, $now, $actorId);
            }

            return $confirmation->fresh();
        });
    }

    public function releaseWaitingForItems(array $itemIds, string $locationId): int
    {
        $itemIds = array_values(array_filter(array_unique($itemIds)));

        if ($itemIds === [] || $locationId !== $this->repository->sourceLocationId()) {
            return 0;
        }

        $waiting = OrderBuyerConfirmation::with('order:id,salesorder_no')
            ->whereIn('item_id', $itemIds)
            ->waitingStock()
            ->orderBy('raised_at')
            ->get();

        if ($waiting->isEmpty()) {
            return 0;
        }

        $binStocks = $this->repository->binStocks($itemIds, $locationId);

        $remaining = [];
        foreach ($itemIds as $itemId) {
            $remaining[$itemId] = array_sum(array_column($binStocks[$itemId] ?? [], 'on_hand'));
        }

        $released = 0;

        foreach ($waiting as $confirmation) {
            $need = max(1, (int) $confirmation->qty_short);

            if (($remaining[$confirmation->item_id] ?? 0) < $need) {
                continue;
            }

            $remaining[$confirmation->item_id] -= $need;

            $confirmation->forceFill(['resolved_at' => now()])->save();
            $this->notifyStockArrived($confirmation);
            $released++;
        }

        return $released;
    }

    private function applyCancel(OrderBuyerConfirmation $confirmation, ?string $note, ?string $actorId): void
    {
        $this->orderService->cancelLocally(
            $confirmation->order_id,
            $note ?: 'Dibatalkan pembeli karena stok kosong',
            $actorId,
        );
    }

    private function applyReplace(OrderBuyerConfirmation $confirmation, ?string $replacementSku): void
    {
        if (! $replacementSku) {
            throw new UserFacingException('SKU pengganti wajib diisi.');
        }

        if (! $confirmation->order_item_id) {
            throw new UserFacingException('Baris pesanan tidak diketahui, tidak bisa diganti.');
        }

        $replacementId = DB::table('product_variants')->where('sku', $replacementSku)->value('id');

        if (! $replacementId) {
            throw new UserFacingException("SKU {$replacementSku} tidak ditemukan di master produk.");
        }

        $this->assertReplacementHasStock((string) $replacementId, $replacementSku, (int) $confirmation->qty_short);

        $this->orderService->updateOrderItem(
            $confirmation->order_id,
            $confirmation->order_item_id,
            ['sku' => $replacementSku],
        );

        $confirmation->forceFill(['replacement_item_id' => $replacementId])->save();
    }

    private function applyRemove(OrderBuyerConfirmation $confirmation): void
    {
        if (! $confirmation->order_item_id) {
            throw new UserFacingException('Baris pesanan tidak diketahui, tidak bisa dihapus.');
        }

        $this->orderService->deleteOrderItem($confirmation->order_id, $confirmation->order_item_id);
    }

    private function assertReplacementHasStock(string $itemId, string $sku, int $qty): void
    {
        $locationId = $this->repository->sourceLocationId();

        if (! $locationId) {
            throw new UserFacingException('Gudang Kecil belum dikonfigurasi.');
        }

        $bins = $this->repository->binStocks([$itemId], (string) $locationId);
        $available = array_sum(array_column($bins[$itemId] ?? [], 'on_hand'));

        if ($available < max(1, $qty)) {
            throw new UserFacingException("Stok {$sku} di Gudang Kecil tidak mencukupi (tersedia {$available}).");
        }
    }

    private function resolveSiblings(OrderBuyerConfirmation $confirmation, $now, ?string $actorId): void
    {
        OrderBuyerConfirmation::where('order_id', $confirmation->order_id)
            ->whereKeyNot($confirmation->id)
            ->unresolved()
            ->update([
                'outcome' => OrderBuyerConfirmation::OUTCOME_CANCEL,
                'confirmed_by' => $actorId,
                'confirmed_at' => $now,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function notifyStockArrived(OrderBuyerConfirmation $confirmation): void
    {
        $orderNo = $confirmation->order?->salesorder_no ?? '-';

        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => 'order_waiting_stock_ready',
            'title' => 'Stok pesanan yang ditunggu sudah masuk',
            'message' => "Pesanan {$orderNo} bisa diselesaikan, stoknya sudah tersedia di Gudang Kecil.",
            'data' => [
                'sales_order_id' => $confirmation->order_id,
                'salesorder_no' => $orderNo,
                'link' => "/dashboard/pesanan/{$confirmation->order_id}",
            ],
        ]);
    }
}
