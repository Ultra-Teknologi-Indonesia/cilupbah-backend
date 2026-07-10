<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Repositories\PriceListRepository;

class PriceListService
{
    public function __construct(private PriceListRepository $repository)
    {
    }

    public function list(?string $productId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->paginate($productId, $perPage);
    }

    public function pricesByIds(array $ids): Collection
    {
        return $this->repository->findByIds($ids);
    }

    public function updatePrices(array $items): int
    {
        $updated = 0;

        foreach ($items as $item) {
            $variant = $this->repository->findVariant($item['variant_id']);
            if (! $variant) {
                continue;
            }

            if (isset($item['sell_price'])) {
                $variant->sell_price = $item['sell_price'];
            }
            if (isset($item['buy_price'])) {
                $variant->buy_price = $item['buy_price'];
            }
            $this->repository->saveVariant($variant);

            if (isset($item['wholesale_prices'])) {
                $this->repository->deleteWholesalePrices($variant->id);
                foreach ($item['wholesale_prices'] as $wp) {
                    $this->repository->createWholesalePrice(array_merge($wp, ['variant_id' => $variant->id]));
                }
            }

            $updated++;
        }

        return $updated;
    }
}
