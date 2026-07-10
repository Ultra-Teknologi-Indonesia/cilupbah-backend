<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Spatie\QueryBuilder\QueryBuilder;

class BundleRepository
{
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::where('is_bundle', true))
            ->with([
                'variants' => fn ($q) => $q->select('id', 'product_id', 'sku', 'sell_price'),
                'bundleItems.component:id,product_id,sku,sell_price',
            ])
            ->allowedSearch('name')
            ->defaultSort('-created_at')
            ->allowedSorts('created_at', 'name')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function findBundleOrFail(string $id): Product
    {
        return Product::where('is_bundle', true)->findOrFail($id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function firstCategoryId(): ?string
    {
        return Category::value('id');
    }

    public function loadDetail(Product $product): Product
    {
        return $product->load(
            'variants:id,product_id,sku,sell_price',
            'bundleItems.component:id,product_id,sku,sell_price',
        );
    }
}
