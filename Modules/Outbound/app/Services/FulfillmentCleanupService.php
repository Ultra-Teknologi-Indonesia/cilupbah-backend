<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\FulfillmentRemoval;
use Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Log;

class FulfillmentCleanupService
{

    public function detachCancelledOrder(string $orderId, ?string $removedBy = 'system'): void
    {

        $order = SalesOrder::find($orderId);

        if (! $order || ($order->status !== 'cancelled' && ! $order->is_canceled)) {

            return;
        }

        $removedBy = $removedBy ?: 'system';
        $stage = null;

        $packlist = Packlist::where('order_id', $orderId)
            ->whereNotIn('status', [Packlist::STATUS_COMPLETED, Packlist::STATUS_CANCELLED])
            ->first();

        if ($packlist) {
            $packlist->update(['status' => Packlist::STATUS_CANCELLED]);
            $stage = FulfillmentRemoval::STAGE_PACKING;
        }

        $picklistIds = PicklistItem::where('order_id', $orderId)
            ->pluck('picklist_id')
            ->filter()
            ->unique()
            ->values();

        if ($picklistIds->isNotEmpty()) {
            PicklistItem::where('order_id', $orderId)->delete();

            $stillUsed = PicklistItem::whereIn('picklist_id', $picklistIds)
                ->distinct()
                ->pluck('picklist_id');

            $orphaned = $picklistIds->diff($stillUsed);

            if ($orphaned->isNotEmpty()) {
                Picklist::whereIn('id', $orphaned)->delete();
            }

            $stage = $stage ?? FulfillmentRemoval::STAGE_PICKING;
        }

        if ($stage === null) {

            return;
        }

        try {
            FulfillmentRemoval::create([
                'order_id'       => $orderId,
                'stage'          => $stage,
                'removed_by'     => $removedBy,
                'reason'         => 'order-cancelled',
                'reversed_stock' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal catat FulfillmentRemoval saat cleanup pembatalan', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
