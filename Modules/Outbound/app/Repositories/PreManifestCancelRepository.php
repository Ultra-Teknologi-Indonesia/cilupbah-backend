<?php

namespace Modules\Outbound\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Modules\Outbound\Models\Packlist;
use Modules\Sales\Models\SalesOrder as Order;
use Spatie\QueryBuilder\QueryBuilder;

class PreManifestCancelRepository
{
    public function baseQuery(): Builder
    {
        $query = Order::query()
            ->where(function (Builder $q) {
                $q->where('status', 'cancelled')
                    ->orWhere('is_canceled', true);
            })
            ->whereNotNull('handed_to_warehouse_at')
            ->whereNull('cancel_dismissed_at')
            ->whereHas('packlist', fn (Builder $packlist): Builder => $packlist
                ->where('status', Packlist::STATUS_COMPLETED))
            ->whereDoesntHave('shipmentOrders');

        WarehouseAccess::apply($query, 'location_id');

        return $query;
    }

    public function paginateList(array $filters = [], int $perPage = 10)
    {
        $query = $this->baseQuery()
            ->select([
                'sales_orders.id',
                'sales_orders.salesorder_no',
                'sales_orders.channel_order_no',
                'sales_orders.customer_name',
                'sales_orders.source',
                'sales_orders.transaction_date',
                'sales_orders.tracking_number',
                'sales_orders.channel_status',
                'sales_orders.cancel_reason',
                'sales_orders.cancel_accepted_at',
            ]);

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (! empty($filters['q'])) {
            request()->query->set('search', $filters['q']);
        }

        return QueryBuilder::for($query)
            ->allowedSearch(...array_merge(Order::SEARCH_COLUMNS, ['items.sku', 'items.description']))
            ->allowedSorts(
                'salesorder_no',
                'source',
                'customer_name',
                'tracking_number',
                'cancel_reason',
                'cancel_accepted_at',
                'transaction_date',
                'location_id',
            )
            ->defaultSort('-cancel_accepted_at')
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
