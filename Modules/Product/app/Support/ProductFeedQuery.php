<?php

namespace Modules\Product\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Models\Category;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductFeedQuery
{
    public const SEARCHABLE = ['name', 'sku'];

    public const SORTS = ['name', 'created_at', 'updated_at'];

    public const DEFAULT_SORT = '-updated_at';

    public static function configure(QueryBuilder $query): QueryBuilder
    {
        return self::applyCriteria($query)
            ->allowedSorts(...self::SORTS)
            ->defaultSort(self::DEFAULT_SORT);
    }

    public static function applyCriteria(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->allowedSearch(...self::SEARCHABLE)
            ->allowedFilters(...self::filters());
    }

    public static function applySort(Builder $query): Builder
    {
        $sort = (string) request()->query('sort', self::DEFAULT_SORT);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTS, true)) {
            $column = 'updated_at';
            $direction = 'desc';
        }

        return $query->orderBy($column, $direction);
    }

    public static function hasCriteria(): bool
    {
        return filled(request()->query('search'))
            || ! empty(array_filter((array) request()->query('filter', [])));
    }

    public static function filters(): array
    {
        return [
            AllowedFilter::callback('category_id', function ($query, $value) {
                $query->whereIn('category_id', self::categoryWithDescendants($value));
            }),

            AllowedFilter::callback('type', function ($query, $value) {
                match ($value) {
                    'bundle' => $query->where('is_bundle', true),
                    'konsinyasi' => $query->where('is_consignment', true),
                    'pre_order' => $query->where('order_type', 'PREORDER'),
                    'satuan' => $query->where('is_bundle', false)
                        ->where('is_consignment', false)
                        ->where(fn ($q) => $q->where('order_type', '<>', 'PREORDER')->orWhereNull('order_type')),
                    default => null,
                };
            }),

            AllowedFilter::callback('min_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '>=', $value))),
            AllowedFilter::callback('max_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '<=', $value))),

            AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelMappings.channelShop.channel', fn ($q) => $q->where('code', $value))),
        ];
    }

    public static function categoryWithDescendants($values): array
    {
        $roots = array_filter(array_map('intval', (array) $values));
        if (empty($roots)) {
            return [0];
        }

        $childrenByParent = [];
        foreach (Category::query()->get(['id', 'parent_id']) as $cat) {
            $childrenByParent[(int) $cat->parent_id][] = (int) $cat->id;
        }

        $ids = [];
        $stack = $roots;
        while ($stack) {
            $cur = array_pop($stack);
            if (in_array($cur, $ids, true)) {
                continue;
            }
            $ids[] = $cur;
            foreach ($childrenByParent[$cur] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }
}
