<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PacklistItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class PacklistRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Packlist::class)
            ->with(['location:id,location_name,location_code', 'packer:id,name,email', 'order:id,salesorder_no,customer_name'])
            ->allowedFilters(
                AllowedFilter::callback('status', function ($query, $value) {
                    $statuses = is_array($value) ? $value : explode(',', $value);
                    $query->whereIn('status', $statuses);
                }),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('packer_id'),
                AllowedFilter::exact('order_id'),
                AllowedFilter::partial('q', 'packlist_no'),

                AllowedFilter::callback('shipping_provider', function ($query, $value) {
                    $query->whereHas('order', fn ($q) => $q->where('shipping_provider', $value));
                }),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereHas('order', fn ($q) => $q->whereDate('transaction_date', '>=', $value));
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereHas('order', fn ($q) => $q->whereDate('transaction_date', '<=', $value));
                }),
                AllowedFilter::callback('label_printed', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') $query->whereHas('order', fn ($q) => $q->whereNotNull('shipping_label_prepared_at'));
                    elseif ($v === 'no') $query->whereHas('order', fn ($q) => $q->whereNull('shipping_label_prepared_at'));
                }),
            )
            ->allowedSorts('created_at', 'packlist_no', 'started_at', 'completed_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?Packlist
    {
        return Packlist::with([
            'items.product:id,sku,product_id',
            'items.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.product.product:id,name',
            'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'items.orderItem:id,sku,description,qty_in_base,item_id',
            'items.orderItem.product:id,sku,product_id',
            'items.orderItem.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.orderItem.product.product:id,name',
            'items.orderItem.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'location:id,location_name,location_code',
            'packer:id,name,email',
            'order:id,salesorder_no,customer_name',
        ])->find($id);
    }

    public function findByOrderId(string $orderId): ?Packlist
    {
        return Packlist::where('order_id', $orderId)
            ->whereNotIn('status', [Packlist::STATUS_CANCELLED])
            ->first();
    }

    public function create(array $data): Packlist
    {
        return Packlist::create($data);
    }

    public function createItem(array $data): PacklistItem
    {
        return PacklistItem::create($data);
    }

    public function updateItem(string $itemId, array $data): bool
    {
        return PacklistItem::where('id', $itemId)->update($data) > 0;
    }

    public function update(string $id, array $data): bool
    {
        return Packlist::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Packlist::where('id', $id)->delete() > 0;
    }

    public function getItemsPaginated(string $packlistId, int $limit = 10)
    {
        return QueryBuilder::for(PacklistItem::class)
            ->where('packlist_id', $packlistId)
            ->with(['product:id,sku,product_id', 'orderItem:id,sku,description'])
            ->allowedSorts('created_at', 'sku')
            ->defaultSort('created_at')
            ->paginate($limit);
    }

    public function generatePacklistNo(): string
    {
        $last = Packlist::where('packlist_no', 'like', 'PACK-%')
            ->orderByDesc('packlist_no')
            ->value('packlist_no');

        $seq = $last ? (int) substr($last, 5) + 1 : 1;

        return 'PACK-' . str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
