<?php

namespace Modules\Outbound\Services;

use Modules\Order\Models\Order;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class OutboundFulfillmentService
{
    public function getOrdersByStage(string $stage, int $limit = 10)
    {
        $query = match ($stage) {
            'ready-to-process' => $this->readyToProcess(),
            'ready-to-pick' => $this->readyToPick(),
            'on-picking' => $this->onPicking(),
            'finish-pick' => $this->finishPick(),
            'on-packing' => $this->onPacking(),
            'finish-pack' => $this->finishPack(),
            'ready-to-ship' => $this->readyToShip(),
            'shipped' => $this->shipped(),
            default => throw new \Exception("Stage '{$stage}' tidak dikenal."),
        };

        return QueryBuilder::for($query)
            ->allowedFilters(
                AllowedFilter::partial('q', 'salesorder_no'),
                AllowedFilter::exact('source'),
            )
            ->allowedSorts('transaction_date', 'created_at', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    private function readyToProcess()
    {
        return Order::where('status', 'reserved')
            ->whereDoesntHave('picklistItems');
    }

    private function readyToPick()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_DRAFT));
            });
    }

    private function onPicking()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_IN_PROGRESS));
            });
    }

    private function finishPick()
    {
        return Order::where('status', 'picked')
            ->whereDoesntHave('packlist', fn ($q) => $q->whereIn('status', [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS, Packlist::STATUS_COMPLETED]));
    }

    private function onPacking()
    {
        return Order::where('status', 'picked')
            ->whereHas('packlist', fn ($q) => $q->where('status', Packlist::STATUS_IN_PROGRESS));
    }

    private function finishPack()
    {
        return Order::where('status', 'packed')
            ->whereDoesntHave('shipmentOrders');
    }

    private function readyToShip()
    {
        return Order::where('status', 'packed')
            ->whereHas('shipmentOrders', function ($q) {
                $q->whereHas('shipment', fn ($sq) => $sq->where('status', 'SCHEDULED'));
            });
    }

    private function shipped()
    {
        return Order::where('status', 'shipped');
    }
}
