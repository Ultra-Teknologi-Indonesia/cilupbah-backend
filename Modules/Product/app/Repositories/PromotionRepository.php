<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Promotion;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PromotionRepository
{

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(Promotion::class)
            ->withCount('items')
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('type'),
            )
            ->allowedSorts('name', 'start_at', 'end_at', 'created_at')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findById(string $id): ?Promotion
    {
        return Promotion::with('items.product:id,name,sku')->find($id);
    }

    public function create(array $attributes, array $productIds): Promotion
    {
        return DB::transaction(function () use ($attributes, $productIds) {
            $promotion = Promotion::create($attributes);

            foreach ($productIds as $productId) {
                $promotion->items()->create(['product_id' => $productId]);
            }

            return $promotion->load('items.product:id,name,sku');
        });
    }

    public function delete(Promotion $promotion): void
    {
        DB::transaction(function () use ($promotion) {
            $promotion->items()->delete();
            $promotion->delete();
        });
    }
}
