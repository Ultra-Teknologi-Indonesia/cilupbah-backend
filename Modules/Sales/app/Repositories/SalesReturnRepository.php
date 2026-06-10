<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class SalesReturnRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturn::class)
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items.product:id,sku,product_id', 'items.product.product:id,name'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source'),
                AllowedFilter::exact('order_id'),
                AllowedFilter::custom('search', new FuzzyFilter('return_number,customer_name'))
            )
            ->allowedSorts('return_number', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getUnprocessedMarketplace(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturn::class)
            ->unprocessed()
            ->marketplace()
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items.product:id,sku,product_id', 'items.product.product:id,name'])
            ->defaultSort('-created_at')
            ->paginate($limit);
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
                AllowedFilter::custom('search', new FuzzyFilter('return_number,customer_name'))
            )
            ->defaultSort('-created_at')
            ->paginate($limit);
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
            ->paginate($limit);
    }

    public function getRejectedReturnItems(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnItem::class)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_REJECTED))
            ->with(['salesReturn:id,return_number,status', 'product:id,sku,product_id'])
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getResolvedReturnItems(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnItem::class)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_COMPLETED))
            ->with(['salesReturn:id,return_number,status', 'product:id,sku,product_id'])
            ->defaultSort('-created_at')
            ->paginate($limit);
    }
}
