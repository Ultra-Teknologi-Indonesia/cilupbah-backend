<?php

namespace Modules\Sales\Repositories;

use App\Support\WarehouseAccess;
use Modules\Sales\Models\OrderBuyerConfirmation;
use Spatie\QueryBuilder\QueryBuilder;

class BuyerConfirmationRepository
{
    public function paginate(string $state)
    {
        $query = QueryBuilder::for(OrderBuyerConfirmation::class)
            ->with([
                'order:id,salesorder_no,transaction_date,customer_name,source,status',
                'product:id,sku',
                'replacement:id,sku',
            ])
            ->when(
                $state === 'waiting-stock',
                fn ($query) => $query->waitingStock(),
                fn ($query) => $query->awaitingDecision(),
            )
            ->allowedFilters(['order_id', 'item_id', 'outcome'])
            ->allowedSorts('raised_at', 'confirmed_at', 'qty_short')
            ->defaultSort('-raised_at');
        $query->whereHas('order', fn ($order) => WarehouseAccess::apply($order, 'location_id'));

        return $query->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function forOrder(string $orderId)
    {
        return OrderBuyerConfirmation::with(['product:id,sku', 'replacement:id,sku'])
            ->where('order_id', $orderId)
            ->whereHas('order', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->orderByDesc('raised_at')
            ->get();
    }
}
