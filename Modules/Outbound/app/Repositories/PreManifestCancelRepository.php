<?php

namespace Modules\Outbound\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Models\SalesOrder as Order;

class PreManifestCancelRepository
{

    public function baseQuery(): Builder
    {
        return Order::query()
            ->where('status', 'cancelled')
            ->whereNotNull('handed_to_warehouse_at')
            ->whereNull('cancel_dismissed_at')
            ->whereDoesntHave('shipmentOrders');
    }

    public function paginateList(array $filters = [], int $perPage = 10)
    {
        $query = $this->baseQuery()
            ->with(['location:id,location_name,location_code'])
            ->latest('cancel_accepted_at');

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (! empty($filters['q'])) {

            request()->query->set('search', $filters['q']);
            $query->allowedSearch(...Order::SEARCH_COLUMNS);
        }

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
