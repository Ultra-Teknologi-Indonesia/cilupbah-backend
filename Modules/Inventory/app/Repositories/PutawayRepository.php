<?php

namespace Modules\Inventory\Repositories;

use App\Models\User;
use App\Support\SearchExpression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class PutawayRepository
{
    public function getPutawayBins(string $locationId, ?string $search = null): Collection
    {
        $query = LocationBin::where('location_id', $locationId)
            ->where('is_inbound', false)
            ->orderBy('bin_final_code');

        $query->allowedSearch('bin_final_code');

        return $query->get(['id', 'bin_final_code']);
    }

    public function currentQtyByBin(string $locationId, array $binIds): Collection
    {
        return Inventory::where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->whereIn('bin_id', $binIds)
            ->groupBy('bin_id')
            ->selectRaw('bin_id, SUM(on_hand) as total')
            ->pluck('total', 'bin_id')
            ->map(fn ($v) => (int) $v);
    }

    public function lookupPutawayBin(string $locationId, string $code): ?LocationBin
    {
        return LocationBin::where('location_id', $locationId)
            ->where('is_inbound', false)
            ->where('bin_final_code', $code)
            ->first();
    }

    public function sumOnHandForBin(string $binId, string $locationId): int
    {
        return (int) Inventory::where('bin_id', $binId)
            ->where('location_id', $locationId)
            ->sum('on_hand');
    }

    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(Putaway::class)
            ->with(['location:id,location_name', 'assignee:id,name', 'creator:id,name', 'items:id,putaway_id,item_id,qty,putaway_qty', 'inbound:id,reference_number,created_at', 'sources:id,reference_number,transaction_number'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('assigned_to'),
                AllowedFilter::exact('source_type'),
            )
            ->allowedSorts('created_at', 'started_at', 'completed_at', 'putaway_no', 'status');

        $this->applySearch($query);

        $query
            ->defaultSort('-created_at');

        \App\Support\WarehouseAccess::apply($query);

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getByStatus(string $status, int $limit = 10)
    {
        $query = QueryBuilder::for(Putaway::where('status', $status))
            ->with(['location:id,location_name', 'assignee:id,name', 'creator:id,name', 'items:id,putaway_id,item_id,qty,putaway_qty', 'sources:id,reference_number,transaction_number'])
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('assigned_to'),
            )
            ->allowedSorts('created_at', 'started_at', 'completed_at', 'putaway_no', 'status');

        $this->applySearch($query);

        $query
            ->defaultSort('-created_at');

        \App\Support\WarehouseAccess::apply($query);

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    private function applySearch(QueryBuilder $query): void
    {
        $term = trim((string) (request()->query('search') ?? request()->query('q', '')));

        if ($term === '') {
            return;
        }

        $putawaysTable = $query->getModel()->getTable();
        $usersTable = (new User())->getTable();

        $query->where(function (EloquentBuilder $search) use ($term, $putawaysTable, $usersTable): void {
            $search
                ->whereRaw(
                    SearchExpression::match(["{$putawaysTable}.putaway_no"]),
                    SearchExpression::matchBindings($term, ["{$putawaysTable}.putaway_no"]),
                )

                ->orWhereRaw(
                    SearchExpression::match(["{$putawaysTable}.created_by"]),
                    SearchExpression::matchBindings($term, ["{$putawaysTable}.created_by"]),
                )
                ->orWhereHas('sources', function (EloquentBuilder $sourceQuery) use ($term): void {
                    $columns = ['reference_number', 'transaction_number'];
                    $sourceQuery->whereRaw(
                        SearchExpression::match($columns),
                        SearchExpression::matchBindings($term, $columns),
                    );
                })
                ->orWhereExists(function ($userQuery) use ($term, $putawaysTable, $usersTable): void {
                    $columns = ["{$usersTable}.name"];
                    $userQuery
                        ->selectRaw('1')
                        ->from($usersTable)
                        ->whereRaw("{$putawaysTable}.created_by::text = {$usersTable}.id::text")
                        ->whereRaw(
                            SearchExpression::match($columns),
                            SearchExpression::matchBindings($term, $columns),
                        );
                })
                ->orWhereExists(function ($userQuery) use ($term, $putawaysTable, $usersTable): void {
                    $columns = ["{$usersTable}.name"];
                    $userQuery
                        ->selectRaw('1')
                        ->from($usersTable)
                        ->whereRaw("{$putawaysTable}.assigned_to::text = {$usersTable}.id::text")
                        ->whereRaw(
                            SearchExpression::match($columns),
                            SearchExpression::matchBindings($term, $columns),
                        );
                });
        });
    }

    private function detailRelations(): array
    {
        return [
            'items.product:id,sku,product_id',
            'items.product.product:id,name',
            'items.product.options:id,variant_id,value',
            'items.product.media' => fn ($q) => $q->orderBy('sort_order')->limit(1),
            'items.sourceBin:id,bin_final_code',
            'items.destinationBin:id,bin_final_code',
            'items.placements:id,putaway_item_id,bin_id,qty',
            'items.placements.bin:id,bin_final_code',
            'location:id,location_name',
            'assignee:id,name',
            'creator:id,name',
            'inbound:id,reference_number,transaction_number',
            'sources:id,reference_number,transaction_number',
        ];
    }

    public function findById(string $id): ?Putaway
    {
        return Putaway::with($this->detailRelations())->find($id);
    }

    public function getManyWithDetails(array $ids)
    {
        return Putaway::with($this->detailRelations())
            ->whereIn('id', $ids)
            ->orderBy('putaway_no')
            ->get();
    }

    public function findByIdForUpdate(string $id): ?Putaway
    {
        return Putaway::lockForUpdate()->find($id);
    }

    public function create(array $data): Putaway
    {
        return Putaway::create($data);
    }

    public function createItem(array $data): PutawayItem
    {
        return PutawayItem::create($data);
    }

    public function updateStatus(string $id, string $status, array $extra = []): bool
    {
        return Putaway::where('id', $id)->update(array_merge(['status' => $status], $extra));
    }

    public function getItemsPaginated(string $putawayId, int $limit = 10)
    {
        return QueryBuilder::for(PutawayItem::where('putaway_id', $putawayId))
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.options:id,variant_id,value',
                'product.media' => fn ($q) => $q->orderBy('sort_order')->limit(1),
                'sourceBin:id,bin_final_code',
                'destinationBin:id,bin_final_code',
                'placements:id,putaway_item_id,bin_id,qty',
                'placements.bin:id,bin_final_code',
            ])
            ->allowedSorts('created_at')
            ->defaultSort('created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findItemForUpdate(string $putawayId, string $itemId): ?PutawayItem
    {
        return PutawayItem::where('putaway_id', $putawayId)
            ->where('id', $itemId)
            ->lockForUpdate()
            ->first();
    }

    public function getByAssignee(int $assigneeId, int $limit = 10)
    {
        return Putaway::where('assigned_to', $assigneeId)
            ->whereIn('status', [Putaway::STATUS_NOT_STARTED, Putaway::STATUS_IN_PROGRESS])
            ->with(['location:id,location_name'])
            ->orderByDesc('created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function generatePutawayNo(): string
    {
        $prefix = "PUT-";

        $last = Putaway::whereRaw("putaway_no ~ '^PUT-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(putaway_no FROM 5) AS INTEGER) DESC")
            ->value('putaway_no');

        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
    }
}
