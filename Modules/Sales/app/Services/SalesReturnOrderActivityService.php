<?php

namespace Modules\Sales\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundReceipt;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Models\SalesReturn;

class SalesReturnOrderActivityService
{
    public function __construct(protected SalesOrderService $orderService) {}

    public function returnCreated(SalesReturn $return, ?string $actorId): void
    {
        $this->record($return, OrderActivityAction::RETURN_CREATED, "return-created:{$return->id}", "Dokumen retur {$return->return_number} dibuat — menunggu paket fisik.", $actorId);
    }

    public function returnAccepted(SalesReturn $return, ?string $actorId): void
    {
        $this->record($return, OrderActivityAction::RETURN_ACCEPTED, "return-accepted:{$return->id}", "Retur {$return->return_number} disetujui. Paket fisik wajib diterima dan di-putaway sebelum stok tersedia.", $actorId);
    }

    public function returnRejected(SalesReturn $return, ?string $actorId, ?string $reason): void
    {
        $suffix = filled($reason) ? " Alasan: {$reason}." : '';
        $this->record($return, OrderActivityAction::RETURN_REJECTED, "return-rejected:{$return->id}", "Retur {$return->return_number} ditolak.{$suffix}", $actorId);
    }

    public function inboundCreated(SalesReturn $return, Inbound $inbound, ?string $actorId): void
    {
        $this->record($return, OrderActivityAction::RETURN_INBOUND_CREATED, "return-inbound-created:{$inbound->id}", "Penerimaan {$inbound->transaction_number} dibuat untuk retur {$return->return_number}; stok belum tersedia sampai scan dan putaway selesai.", $actorId, $inbound->id, $inbound->transaction_number);
    }

    /** @param list<string> $receiptIds */
    public function inboundReceived(Inbound $inbound, array $receiptIds, ?string $actorId): void
    {
        if ($inbound->source_type !== 'sales_return' || ! $inbound->source_id || $receiptIds === []) {
            return;
        }

        $return = SalesReturn::query()->find($inbound->source_id);
        $receipts = InboundReceipt::query()->with('inboundItem.variant:id,sku')->whereIn('id', $receiptIds)->orderBy('id')->get();
        if (! $return || $receipts->isEmpty()) {
            return;
        }

        $items = $receipts->map(function (InboundReceipt $receipt): string {
            $sku = $receipt->inboundItem?->variant?->sku ?? 'SKU tidak dikenal';

            return "{$sku} × {$receipt->qty} (".strtoupper((string) ($receipt->condition ?? 'GOOD')).')';
        })->implode(', ');
        $eventKey = 'return-inbound-received:'.$inbound->id.':'.implode(',', $receipts->pluck('id')->sort()->all());

        $this->record($return, OrderActivityAction::RETURN_RECEIVED, $eventKey, "Paket diterima pada {$inbound->transaction_number}: {$items}. Stok masih berada di area penerimaan dan belum tersedia.", $actorId, $inbound->id, $inbound->transaction_number);
    }

    public function putawayCompleted(Putaway $putaway): void
    {
        if ($putaway->source_type !== 'INBOUND') {
            return;
        }

        $inboundIds = PutawaySource::query()->where('putaway_id', $putaway->id)->pluck('inbound_id');
        if ($putaway->source_id) {
            $inboundIds->push($putaway->source_id);
        }
        $inbounds = Inbound::query()->whereIn('id', $inboundIds->filter()->unique())->where('source_type', 'sales_return')->whereNotNull('source_id')->get();
        if ($inbounds->isEmpty()) {
            return;
        }

        $putaway->loadMissing('items.placements.bin:id,bin_final_code');
        $bins = $putaway->items->flatMap(fn ($item) => $item->placements)->map(fn (PutawayPlacement $placement) => $placement->bin?->bin_final_code)->filter()->unique()->values();
        $binLabel = $bins->isEmpty() ? 'rak tujuan' : $bins->implode(', ');
        $putawayNo = $putaway->putaway_no ?? (string) $putaway->id;

        foreach ($inbounds as $inbound) {
            $return = SalesReturn::query()->find($inbound->source_id);
            if ($return) {
                $this->record($return, OrderActivityAction::RETURN_PUTAWAY_COMPLETED, "return-putaway-completed:{$putaway->id}:{$inbound->id}", "Putaway {$putawayNo} selesai ke {$binLabel}; stok retur kembali tersedia.", $putaway->assigned_to, $putaway->id, $putawayNo);
            }
        }
    }

    public function returnCompleted(SalesReturn $return, ?string $actorId): void
    {
        $this->record($return, OrderActivityAction::RETURN_COMPLETED, "return-completed:{$return->id}", "Retur {$return->return_number} selesai setelah penerimaan dan putaway lengkap.", $actorId);
    }

    private function record(SalesReturn $return, OrderActivityAction $action, string $eventKey, string $note, ?string $actorId, ?string $entityId = null, ?string $entityNumber = null): void
    {
        if (! $return->order_id || ! ($order = SalesOrder::query()->find($return->order_id))) {
            return;
        }
        $entityId ??= $return->id;
        $exists = SalesOrderStatusHistory::query()->where('salesorder_id', $order->id)->where('action', $action->value)->where('entity_id', $entityId)->get(['metadata'])->contains(fn (SalesOrderStatusHistory $history): bool => ($history->metadata['event_key'] ?? null) === $eventKey);
        if ($exists) {
            return;
        }

        $this->orderService->logStatusHistory($order, $action, [
            'event_key' => $eventKey,
            'entity_no' => $entityNumber ?? $return->return_number,
            'return_number' => $return->return_number,
            'note' => $note,
        ], $this->actor($actorId), entityId: $entityId);
    }

    private function actor(?string $actorId): array
    {
        $user = $actorId && Str::isUuid($actorId)
            ? User::query()->find($actorId, ['id', 'name', 'email'])
            : null;

        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : ['name' => 'System', 'email' => 'system'];
    }
}
