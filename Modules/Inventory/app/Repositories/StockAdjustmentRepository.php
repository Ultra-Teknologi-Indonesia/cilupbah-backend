<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Http\Request;

class StockAdjustmentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(StockAdjustment::class)
            ->select([
                'id',
                'adjustment_no',
                'transaction_date',
                'location_id',
                'is_beginning_balance',
                'notes',
                'created_by',
                'created_at',
                'updated_at',
            ])
            ->with(['location:id,location_name'])
            ->allowedSearch('adjustment_no', 'notes')
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('is_beginning_balance'),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->whereDate('transaction_date', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->whereDate('transaction_date', '<=', $value)),
            )
            ->allowedSorts('transaction_date', 'created_at', 'adjustment_no', 'created_by', 'id')
            ->defaultSort('-transaction_date')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getQueryForExport(Request $request): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        return StockAdjustmentItem::query()
            ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
            ->join('product_variants', 'stock_adjustment_items.item_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('locations', 'stock_adjustments.location_id', '=', 'locations.id')
            ->whereNull('stock_adjustments.deleted_at')
            ->whereNull('product_variants.deleted_at')
            ->when($request->filled('filter[location_id]'), fn($q) => $q->where('stock_adjustments.location_id', $request->input('filter[location_id]')))
            ->when($request->filled('filter[date_from]'), fn($q) => $q->whereDate('stock_adjustments.transaction_date', '>=', $request->input('filter[date_from]')))
            ->when($request->filled('filter[date_to]'), fn($q) => $q->whereDate('stock_adjustments.transaction_date', '<=', $request->input('filter[date_to]')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->input('search'));
                $escaped = addcslashes($term, "\\%_");
                $pattern = "%{$escaped}%";

                return $q->where(function ($searchQuery) use ($pattern) {
                    $searchQuery
                        ->whereRaw("stock_adjustments.adjustment_no ILIKE ? ESCAPE '\\'", [$pattern])
                        ->orWhereRaw("stock_adjustments.notes ILIKE ? ESCAPE '\\'", [$pattern]);
                });
            })
            ->select([
                'stock_adjustments.adjustment_no',
                'stock_adjustments.transaction_date',
                'stock_adjustments.created_at',
                'stock_adjustments.notes as doc_notes',
                'stock_adjustments.is_beginning_balance',
                'stock_adjustments.created_by',
                'locations.location_name',
                'stock_adjustment_items.notes as item_notes',
                'stock_adjustment_items.difference_qty',
                'stock_adjustment_items.unit_cost',
                'product_variants.sku',
                'products.name as product_name',
            ])
            ->orderBy('stock_adjustments.transaction_date', 'desc')
            ->orderBy('stock_adjustments.adjustment_no', 'desc');
    }

    public function findById(string $id): ?StockAdjustment
    {
        return StockAdjustment::with(['location:id,location_name'])
            ->find($id);
    }

    public function findByIdForUpdate(string $id): ?StockAdjustment
    {
        return StockAdjustment::lockForUpdate()->find($id);
    }

    public function create(array $data): StockAdjustment
    {
        return StockAdjustment::create($data);
    }

    public function createItem(array $data): StockAdjustmentItem
    {
        return StockAdjustmentItem::create($data);
    }

    public function delete(string $id): void
    {
        StockAdjustment::where('id', $id)->delete();
    }

    public function getItemsPaginated(string $adjustmentId, int $limit = 10)
    {
        return QueryBuilder::for(StockAdjustmentItem::class)
            ->where('stock_adjustment_id', $adjustmentId)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.media',
                'product.product.media',
                'bin:id,bin_final_code'
            ])
            ->allowedSearch('notes')
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findForPdf(string $id): ?StockAdjustment
    {
        return StockAdjustment::with([
            'items.product.product',
            'items.bin',
            'location',
        ])->find($id);
    }

    public function getManyForPdf(array $ids)
    {
        return StockAdjustment::with([
            'items.product.product',
            'items.bin',
            'location',
        ])
            ->whereIn('id', $ids)
            ->orderBy('transaction_date')
            ->orderBy('adjustment_no')
            ->get();
    }

    public function generateAdjustmentNo(): string
    {
        $prefix = 'ADJ-';

        $last = StockAdjustment::withTrashed()
            ->whereRaw("adjustment_no ~ '^ADJ-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(adjustment_no FROM 5) AS INTEGER) DESC")
            ->value('adjustment_no');

        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
    }
}
