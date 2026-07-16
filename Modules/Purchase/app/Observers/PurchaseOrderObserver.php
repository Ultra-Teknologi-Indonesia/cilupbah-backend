<?php

namespace Modules\Purchase\Observers;

use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Services\InboundService;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderObserver
{
    public function __construct(protected InboundService $inboundService) {}

    /**
     * F5: Auto-create Inbound DRAFT saat PO transisi ke OPEN (siap-terima).
     * Idempoten: skip kalau sudah ada Inbound aktif untuk PO ini.
     */
    public function updated(PurchaseOrder $po): void
    {
        if (! $po->wasChanged('status')) {
            return;
        }

        if ($po->status !== PurchaseOrder::STATUS_OPEN) {
            return;
        }

        $original = $po->getOriginal('status');
        if ($original === PurchaseOrder::STATUS_OPEN) {
            return;
        }

        $this->dispatchDraftCreation($po, $po->updated_by ?? $po->created_by ?? 'system');
    }

    /**
     * Same trigger untuk PO yang langsung dibuat OPEN (skip DRAFT).
     */
    public function created(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrder::STATUS_OPEN) {
            return;
        }

        $this->dispatchDraftCreation($po, $po->created_by ?? 'system');
    }

    /**
     * Tunda pembuatan Inbound sampai transaction commit — supaya PO items
     * yang dibuat setelah PO row save (di dalam transaction yang sama)
     * sudah persistent saat createDraftFromPO() jalan.
     */
    protected function dispatchDraftCreation(PurchaseOrder $po, string $createdBy): void
    {
        $poId = $po->id;
        $inboundService = $this->inboundService;

        DB::afterCommit(function () use ($poId, $createdBy, $inboundService) {
            $exists = Inbound::where('source_type', 'purchase_order')
                ->where('source_id', $poId)
                ->where('status', '!=', Inbound::STATUS_CANCELLED)
                ->exists();

            if ($exists) {
                return;
            }

            $freshPo = PurchaseOrder::with('items')->find($poId);
            if (! $freshPo || $freshPo->status !== PurchaseOrder::STATUS_OPEN) {
                return;
            }

            $inboundService->createDraftFromPO(
                po: $freshPo,
                createdBy: $createdBy,
                isAdditional: false,
            );
        });
    }
}
