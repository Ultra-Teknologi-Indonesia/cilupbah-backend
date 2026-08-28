<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PurchaseCostService
{
    public static function effectiveCostSql(string $alias = 'im'): string
    {
        return "COALESCE(
            NULLIF(ABS({$alias}.cost_per_unit), 0),
            NULLIF(ABS({$alias}.total_cost / NULLIF({$alias}.qty, 0)), 0)
        )";
    }

    public function averageCostSubquery(?string $until = null): Builder
    {
        $costSql = self::effectiveCostSql('im');

        return DB::table('inventory_movements as im')
            ->where('im.source', 'PURCHASE')
            ->where('im.qty', '>', 0)
            ->whereRaw("{$costSql} > 0")
            ->when($until, fn (Builder $query, string $date): Builder => $query
                ->where('im.transaction_date', '<=', $date))
            ->groupBy('im.item_id')
            ->select('im.item_id')
            ->selectRaw(
                "SUM(im.qty * {$costSql}) / NULLIF(SUM(im.qty), 0) AS average_cost"
            )
            ->selectRaw('SUM(im.qty) AS costed_qty')
            ->selectRaw("SUM(im.qty * {$costSql}) AS purchase_value");
    }

    public function averageForItemIds(array $itemIds, ?string $until = null): array
    {
        $ids = array_values(array_unique(array_filter($itemIds)));
        if ($ids === []) {
            return [];
        }

        return $this->averageCostSubquery($until)
            ->whereIn('im.item_id', $ids)
            ->pluck('average_cost', 'item_id')
            ->map(fn ($cost): float => round(max(0.0, (float) $cost), 4))
            ->all();
    }

    public function averageForItem(string $itemId, ?string $until = null): ?float
    {
        $costs = $this->averageForItemIds([$itemId], $until);

        return array_key_exists($itemId, $costs) ? $costs[$itemId] : null;
    }

    public function currentCostForItem(string $itemId, ?string $locationId = null): float
    {
        $purchaseCost = $this->averageForItem($itemId);
        if ($purchaseCost !== null && $purchaseCost > 0) {
            return $purchaseCost;
        }

        $query = DB::table('inventories')
            ->where('item_id', $itemId)
            ->where('on_hand', '>', 0)
            ->where('avg_cost', '>', 0)
            ->when($locationId, fn (Builder $builder, string $id): Builder => $builder
                ->where('location_id', $id));

        $qty = (float) (clone $query)->sum('on_hand');
        if ($qty <= 0) {
            return 0.0;
        }

        $value = (float) $query->sum(DB::raw('on_hand * avg_cost'));

        return round(max(0.0, $value / $qty), 4);
    }
}
