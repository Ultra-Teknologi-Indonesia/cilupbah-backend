<?php

namespace Modules\Purchase\Observers;

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

        // Skip kalau sudah ada Inbound aktif.
        $exists = Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->where('status', '!=', Inbound::STATUS_CANCELLED)
            ->exists();

        if ($exists) {
            return;
        }

        $this->inboundService->createDraftFromPO(
            po: $po,
            createdBy: $po->updated_by ?? $po->created_by ?? 'system',
            isAdditional: false,
        );
    }

    /**
     * Same trigger untuk PO yang langsung dibuat OPEN (skip DRAFT).
     */
    public function created(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrder::STATUS_OPEN) {
            return;
        }

        $exists = Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->where('status', '!=', Inbound::STATUS_CANCELLED)
            ->exists();

        if ($exists) {
            return;
        }

        $this->inboundService->createDraftFromPO(
            po: $po,
            createdBy: $po->created_by ?? 'system',
            isAdditional: false,
        );
    }
}
