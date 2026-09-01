<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Models\BinTransferReceipt;
use Spatie\QueryBuilder\QueryBuilder;

class BinTransferRepository
{
    public function paginateTransfers(array $filters = [], int $perPage = 10)
    {
        $query = QueryBuilder::for(BinTransfer::class)
            ->with(['location:id,location_name'])
            ->withCount('items')
            ->allowedSearch('transfer_number')
            ->allowedSorts('transfer_number', 'transfer_date', 'created_at', 'status', 'id')
            ->defaultSort('-transfer_date', '-created_at');

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        WarehouseAccess::apply($query);

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('transfer_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('transfer_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate($perPage)
            ->appends(request()->query());
    }

    public function findTransfer(string $id): ?BinTransfer
    {
        return BinTransfer::with([
            'location:id,location_name,location_code',
            'items.product',
            'items.product.product:id,name',
            'items.product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            'items.product.product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            'items.sourceBin:id,bin_final_code,location_id',
            'items.destinationBin:id,bin_final_code,location_id',
            'receipts' => fn ($q) => $q->orderByDesc('received_at'),
            'receipts.items.destinationBin:id,bin_final_code,location_id',
        ])->find($id);
    }

    public function paginateReceipts(array $filters = [], int $perPage = 10)
    {
        $baseQuery = BinTransferReceipt::query();

        if (trim((string) request('search', '')) !== '') {
            $baseQuery->leftJoin('bin_transfers', 'bin_transfers.id', '=', 'bin_transfer_receipts.bin_transfer_id')
                ->select('bin_transfer_receipts.*');
        }

        $query = QueryBuilder::for($baseQuery)
            ->with([
                'binTransfer:id,transfer_number,transfer_date',
                'location:id,location_name',
            ])
            ->withCount('items')
            ->allowedSearch('bin_transfer_receipts.receipt_number', 'bin_transfers.transfer_number');

        if (! empty($filters['location_id'])) {
            $query->where('bin_transfer_receipts.location_id', $filters['location_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('bin_transfer_receipts.received_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('bin_transfer_receipts.received_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('bin_transfer_receipts.received_at')
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function findReceipt(string $id): ?BinTransferReceipt
    {
        return BinTransferReceipt::with([
            'location:id,location_name,location_code',
            'binTransfer:id,transfer_number,transfer_date,location_id',
            'items.destinationBin:id,bin_final_code,location_id',
            'items.transferItem.product',
            'items.transferItem.product.product:id,name',
        ])->find($id);
    }
}
