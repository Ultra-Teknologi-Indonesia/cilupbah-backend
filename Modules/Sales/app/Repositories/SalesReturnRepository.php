<?php

namespace Modules\Sales\Repositories;

use Illuminate\Support\Collection;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class SalesReturnRepository
{

    /**
     * Kolom pesanan sengaja memakai awalan relasi (`order.`), bukan nama tabel
     * hasil join. Dengan awalan relasi, macro `allowedSearch` memecahnya menjadi
     * `orWhereHas('order', ...)` sehingga tiap sisi pencarian jatuh pada satu
     * tabel dan bisa memakai index GIN-nya masing-masing. Ekspresi tsvector yang
     * menggabungkan kolom lintas tabel tidak bisa diindeks sama sekali.
     */
    private const SEARCH_COLUMNS = [
        'sales_returns.return_number',
        'sales_returns.customer_name',
        'sales_returns.return_tracking_number',
        'order.channel_order_no',
        'order.salesorder_no',
    ];

    private function withOrderJoin(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_returns.order_id')
            ->select('sales_returns.*');
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->withOrderJoin(QueryBuilder::for(SalesReturn::class))
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items.product:id,sku,product_id', 'items.product.product:id,name'])
            ->allowedFilters(
                AllowedFilter::exact('status', 'sales_returns.status'),
                AllowedFilter::exact('source', 'sales_returns.source'),
                AllowedFilter::exact('order_id', 'sales_returns.order_id'),
                AllowedFilter::exact('reason_category', 'sales_returns.reason_category'),
            )
            ->allowedSearch(...self::SEARCH_COLUMNS)
            ->allowedSorts(
                AllowedSort::field('return_number', 'sales_returns.return_number'),
                AllowedSort::field('created_at', 'sales_returns.created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getReportPaginated(array $filters, int $limit = 10)
    {
        return QueryBuilder::for(SalesReturn::class)
            ->with([
                'order:id,salesorder_no,channel_order_no,customer_name',
                'location:id,location_name',
                'settlement.refunds',
            ])
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('location_id', $filters['location_id']))
            ->when(! empty($filters['channel_shop_id']), fn ($q) => $q->where('channel_shop_id', $filters['channel_shop_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(! empty($filters['reason_category']), fn ($q) => $q->where('reason_category', $filters['reason_category']))
            ->when(! empty($filters['marketplace_decision']), fn ($q) => $q->where('marketplace_decision', $filters['marketplace_decision']))
            ->orderByDesc('created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getAppeals(SalesReturn $return): Collection
    {
        return $return->appeals()->get();
    }

    public function getUnprocessedMarketplace(int $limit = 10)
    {
        return $this->withOrderJoin(QueryBuilder::for(SalesReturn::class))
            ->unprocessed()
            ->marketplace()
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items.product:id,sku,product_id', 'items.product.product:id,name'])
            ->allowedFilters(
                AllowedFilter::exact('location_id', 'sales_returns.location_id'),
                AllowedFilter::exact('reason_category', 'sales_returns.reason_category'),
            )
            ->allowedSearch(...self::SEARCH_COLUMNS)
            ->allowedSorts(
                AllowedSort::field('created_at', 'sales_returns.created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?SalesReturn
    {
        return SalesReturn::with(['order', 'location', 'items.product:id,sku,product_id', 'items.product.product:id,name'])
            ->find($id);
    }

    public function findByIdForUpdate(string $id): ?SalesReturn
    {
        return SalesReturn::with('items')
            ->lockForUpdate()
            ->find($id);
    }

    public function create(array $data): SalesReturn
    {
        return SalesReturn::create($data);
    }

    public function existsByChannelReturn(string $source, string $channelReturnId): bool
    {
        return SalesReturn::where('source', $source)
            ->where('channel_return_id', $channelReturnId)
            ->exists();
    }

    public function existsMarketplaceForOrder(string $orderId): bool
    {
        return SalesReturn::where('order_id', $orderId)
            ->where('source', SalesReturn::SOURCE_MARKETPLACE)
            ->exists();
    }

    public function createItem(array $data): SalesReturnItem
    {
        return SalesReturnItem::create($data);
    }

    public function updateStatus(SalesReturn $return, string $status, ?string $processedBy = null): void
    {
        $updateData = ['status' => $status];
        if ($processedBy) {
            $updateData['processed_by'] = $processedBy;
            $updateData['processed_at'] = now();
        }
        $return->update($updateData);
    }

    public function getUnpaidReturns(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturn::class)
            ->where('status', SalesReturn::STATUS_COMPLETED)
            ->whereDoesntHave('settlement', function ($q) {
                $q->where('status', 'COMPLETED');
            })
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items.product:id,sku,product_id'])
            ->allowedFilters(
                AllowedFilter::exact('source'),
            )
            ->allowedSearch('return_number', 'customer_name', 'return_tracking_number')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getAllReturnItems(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnItem::class)
            ->with(['salesReturn:id,return_number,status', 'product:id,sku,product_id'])
            ->allowedFilters(
                AllowedFilter::exact('sales_return_id'),
                AllowedFilter::exact('condition'),
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getRejectedReturnItems(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnItem::class)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_REJECTED))
            ->with(['salesReturn:id,return_number,status', 'product:id,sku,product_id'])
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getResolvedReturnItems(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnItem::class)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_COMPLETED))
            ->with(['salesReturn:id,return_number,status', 'product:id,sku,product_id'])
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }
}
