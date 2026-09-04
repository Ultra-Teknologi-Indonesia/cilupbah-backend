<?php

namespace Modules\Inventory\Repositories;

use App\Exceptions\UserFacingException;
use App\Models\User;
use App\Support\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryTransferRepository
{
    public function getTransfersPaginated(array $filters = [], int $limit = 10)
    {
        $baseQuery = InventoryTransfer::query();
        $dateColumn = in_array($filters['date_column'] ?? 'created_at', ['created_at', 'received_at'], true)
            ? $filters['date_column'] ?? 'created_at'
            : 'created_at';

        if (trim((string) request('search', '')) !== '') {
            $baseQuery->leftJoin('locations as src_loc', 'src_loc.id', '=', 'inventory_transfers.source_location_id')
                ->leftJoin('locations as dst_loc', 'dst_loc.id', '=', 'inventory_transfers.destination_location_id')
                ->select('inventory_transfers.*');
        }

        $query = QueryBuilder::for($baseQuery)
            ->with([
                'sourceLocation:id,location_name',
                'destinationLocation:id,location_name',
                'items.product:id,sku,product_id',
            ])
            ->allowedSearch('inventory_transfers.transfer_number', 'src_loc.location_name', 'dst_loc.location_name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source_location_id'),
                AllowedFilter::exact('destination_location_id'),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->where(
                    "inventory_transfers.{$dateColumn}",
                    '>=',
                    $this->dateBoundary($value),
                )),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->where(
                    "inventory_transfers.{$dateColumn}",
                    '<',
                    $this->dateBoundary($value, true),
                )),
            )
            ->allowedSorts('transfer_number', 'created_at', 'updated_at', 'shipped_at', 'received_at', 'approved_at', 'status', 'id')
            ->defaultSort('-created_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        if (isset($filters['source_location_id'])) {
            $query->where('source_location_id', $filters['source_location_id']);
        }

        if (isset($filters['destination_location_id'])) {
            $query->where('destination_location_id', $filters['destination_location_id']);
        }

        $allowedLocationIds = WarehouseAccess::allowedIds();
        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds) {
                $q->whereIn('inventory_transfers.source_location_id', $allowedLocationIds)
                    ->orWhereIn('inventory_transfers.destination_location_id', $allowedLocationIds);
            });
        }

        $paginator = $query->paginate(request('per_page', $limit))->appends(request()->query());

        $userIds = [];
        foreach ($paginator->items() as $item) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item->created_by)) {
                $userIds[] = $item->created_by;
            }
        }

        if (! empty($userIds)) {
            $users = User::whereIn('id', array_unique($userIds))->pluck('name', 'id');
            foreach ($paginator->items() as $item) {
                if (isset($users[$item->created_by])) {
                    $item->created_by = $users[$item->created_by];
                }
            }
        }

        return $paginator;
    }

    private function dateBoundary(mixed $value, bool $exclusiveEnd = false): string
    {
        $businessTimezone = (string) config('app.business_timezone', config('app.timezone', 'UTC'));
        $databaseTimezone = (string) config('app.timezone', 'UTC');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim((string) $value), $businessTimezone);

        if ($date === false || $date->format('Y-m-d') !== trim((string) $value)) {
            throw ValidationException::withMessages([
                'filter.date' => 'Format tanggal harus YYYY-MM-DD.',
            ]);
        }

        if ($exclusiveEnd) {
            $date = $date->addDay();
        }

        return $date->setTimezone($databaseTimezone)->format('Y-m-d H:i:s.u');
    }

    public function findById(string $id): ?InventoryTransfer
    {
        $query = InventoryTransfer::with([
            'sourceLocation',
            'destinationLocation',
            'items.product:id,sku,product_id',
            'items.product.product:id,name',
            'items.product.media',
            'items.product.product.media',
            'items.product.options',
            'items.sourceBin:id,bin_final_code',
            'items.destinationBin:id,bin_final_code',
        ]);
        $allowedLocationIds = WarehouseAccess::allowedIds();
        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds) {
                $q->whereIn('source_location_id', $allowedLocationIds)
                    ->orWhereIn('destination_location_id', $allowedLocationIds);
            });
        }
        $transfer = $query->find($id);

        if ($transfer && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $transfer->created_by)) {
            $user = User::find($transfer->created_by);
            if ($user) {
                $transfer->created_by = $user->name;
            }
        }

        return $transfer;
    }

    public function assertManyAccessible(array $ids): void
    {
        $query = InventoryTransfer::query()->whereIn('id', $ids);
        $allowedLocationIds = WarehouseAccess::allowedIds();

        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds): void {
                $q->whereIn('source_location_id', $allowedLocationIds)
                    ->orWhereIn('destination_location_id', $allowedLocationIds);
            });
        }

        $foundIds = [];
        foreach (array_chunk($ids, 1000) as $idChunk) {
            $foundIds = array_merge(
                $foundIds,
                (clone $query)->whereIn('id', $idChunk)->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all(),
            );
        }
        $missingIds = array_values(array_diff($ids, $foundIds));

        if ($missingIds !== []) {
            throw new UserFacingException(
                'Data tidak ditemukan',
                'Sebagian dokumen tidak ditemukan atau tidak dapat diakses: '.implode(', ', $missingIds),
                404,
            );
        }
    }

    public function findByIdForUpdate(string $id): ?InventoryTransfer
    {
        $query = InventoryTransfer::with('items')->lockForUpdate();
        $allowedLocationIds = WarehouseAccess::allowedIds();
        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds) {
                $q->whereIn('source_location_id', $allowedLocationIds)
                    ->orWhereIn('destination_location_id', $allowedLocationIds);
            });
        }

        return $query->find($id);
    }

    public function create(array $data): InventoryTransfer
    {
        return InventoryTransfer::create($data);
    }

    public function createItem(array $data): InventoryTransferItem
    {
        return InventoryTransferItem::create($data);
    }

    public function updateStatus(InventoryTransfer $transfer, string $status): void
    {
        $transfer->update(['status' => $status]);
    }

    public function updateItemReceivedQty(string $itemId, int $addQty): void
    {
        $query = InventoryTransferItem::where('id', $itemId);
        $query->whereHas('transfer', function ($transfer) {
            $allowed = WarehouseAccess::allowedIds();
            if ($allowed !== null) {
                $transfer->where(function ($q) use ($allowed) {
                    $q->whereIn('source_location_id', $allowed)
                        ->orWhereIn('destination_location_id', $allowed);
                });
            }
        });
        $query
            ->increment('received_qty', $addQty);
    }

    public function delete(string $id): bool
    {
        $query = InventoryTransfer::whereKey($id);
        $allowedLocationIds = WarehouseAccess::allowedIds();
        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds) {
                $q->whereIn('source_location_id', $allowedLocationIds)
                    ->orWhereIn('destination_location_id', $allowedLocationIds);
            });
        }
        $transfer = $query->first();

        return $transfer?->delete() ?? false;
    }
}
