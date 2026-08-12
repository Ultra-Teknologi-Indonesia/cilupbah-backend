<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\OrderBuyerConfirmation;
use Spatie\QueryBuilder\QueryBuilder;

class BuyerConfirmationRepository
{
    public function paginate(string $state)
    {
        return QueryBuilder::for(OrderBuyerConfirmation::class)
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
            ->defaultSort('raised_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function forOrder(string $orderId)
    {
        return OrderBuyerConfirmation::with(['product:id,sku', 'replacement:id,sku'])
            ->where('order_id', $orderId)
            ->orderByDesc('raised_at')
            ->get();
    }
}
